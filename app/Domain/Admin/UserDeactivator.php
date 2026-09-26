<?php

namespace App\Domain\Admin;

use App\Domain\Tasks\TaskNotifier;
use App\Domain\Time\TimerService;
use App\Models\ActiveTimer;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Baja de un usuario (SPEC §14): nunca se borra; se desactiva con este asistente, en una transacción:
 *   1. para su temporizador con TimerService::stop (imputa lo medido); si la imputación falla
 *      (semana enviada, bolsa `block` sin saldo…), lo descarta y lo cuenta en el resultado,
 *   2. reasigna sus tareas abiertas a otra persona interna activa o las deja sin asignar; si la
 *      persona nueva no es miembro del proyecto, se añade como miembro para que pueda imputar.
 *      Como al asignar desde la tarea (TaskWriter), la persona nueva pasa a seguirla y recibe el
 *      aviso de asignación (SPEC §13), solo si toda la baja termina bien (TaskNotifier::capture),
 *   3. traspasa la gestión principal de los proyectos no archivados que dirige a quien se elija
 *      (D-032: el nuevo gestor principal queda como miembro gestor); los que no se elijan siguen
 *      igual y se pueden cambiar después en los ajustes del proyecto,
 *   4. deja de ser responsable de sus departamentos (así las aprobaciones de su equipo no esperan
 *      a alguien desactivado, D-020 y D-034),
 *   5. is_active = false: User::booted cierra sus sesiones y su «Recordarme».
 * Sus horas y su historial se conservan intactos.
 */
final class UserDeactivator
{
    public function __construct(
        private readonly TimerService $timers,
        private readonly UserGuard $guard,
        private readonly TaskNotifier $notifier,
    ) {}

    /**
     * @param  array<int, int|null>  $assignments  task_id => nueva persona (null = sin asignar).
     * @param  int|null  $defaultAssignee  Para las tareas abiertas que no estén en $assignments.
     * @param  array<int, int>  $owners  project_id => nuevo gestor principal (el resto sigue igual).
     *
     * @throws ValidationException
     */
    public function deactivate(User $actor, User $user, array $assignments, ?int $defaultAssignee, array $owners = []): DeactivationResult
    {
        return $this->notifier->capture(fn (): DeactivationResult => DB::transaction(function () use ($actor, $user, $assignments, $defaultAssignee, $owners): DeactivationResult {
            $this->guard->lockActiveAdmins();

            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->guard->assertCanDeactivate($actor, $locked);

            $result = new DeactivationResult;

            $this->stopTimer($locked, $result);
            $this->reassignTasks($actor, $locked, $assignments, $defaultAssignee, $result);
            $this->transferProjects($locked, $owners, $result);

            $result->departmentsLeft = $locked->managedDepartments()->count();
            $locked->managedDepartments()->detach();

            $locked->is_active = false;
            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), true);

            return $result;
        }));
    }

    private function stopTimer(User $user, DeactivationResult $result): void
    {
        if (! ActiveTimer::query()->whereKey($user->id)->exists()) {
            return;
        }

        try {
            $entries = $this->timers->stop($user);
        } catch (ValidationException $exception) {
            $this->timers->discard($user);
            $result->timer = DeactivationResult::TIMER_DISCARDED;
            $result->timerErrors = array_values(array_unique(array_merge(...array_values($exception->errors()))));

            return;
        }

        if ($entries === []) {
            $result->timer = DeactivationResult::TIMER_TOO_SHORT;

            return;
        }

        $result->timer = DeactivationResult::TIMER_STOPPED;
        $result->timerMinutes = array_sum(array_map(fn ($entry): int => $entry->entry->minutes, $entries));
    }

    /**
     * @param  array<int, int|null>  $assignments
     */
    private function reassignTasks(User $actor, User $user, array $assignments, ?int $defaultAssignee, DeactivationResult $result): void
    {
        $tasks = Task::query()
            ->open()
            ->assignedTo($user)
            ->with(['project' => fn ($query) => $query->withTrashed()->select(['id', 'owner_user_id', 'name'])])
            ->get(['id', 'project_id', 'assignee_user_id', 'status_id', 'title']);

        if ($tasks->isEmpty()) {
            return;
        }

        $targets = [];
        foreach ($tasks as $task) {
            $targets[$task->id] = array_key_exists($task->id, $assignments) ? $assignments[$task->id] : $defaultAssignee;
        }

        $this->ensureMemberships($tasks, $targets);

        foreach ($tasks as $task) {
            $task->assignee_user_id = $targets[$task->id];
            $task->save();

            if ($targets[$task->id] === null) {
                $result->unassigned++;

                continue;
            }

            $result->reassigned++;
            $task->watchers()->syncWithoutDetaching([$targets[$task->id]]);
            $this->notifier->assigned($task, $actor);
        }
    }

    /**
     * Nuevo gestor principal de los proyectos no archivados que dirige (solo los elegidos). Cada
     * cambio queda en la auditoría del proyecto.
     *
     * @param  array<int, int>  $owners
     */
    private function transferProjects(User $user, array $owners, DeactivationResult $result): void
    {
        if ($owners === []) {
            return;
        }

        $projects = Project::query()
            ->where('owner_user_id', $user->id)
            ->notArchived()
            ->whereKey(array_keys($owners))
            ->lockForUpdate()
            ->get();

        foreach ($projects as $project) {
            $project->owner_user_id = $owners[$project->id];
            $project->save();
            $project->addMember($owners[$project->id], isManager: true);

            $result->projectsTransferred++;
        }
    }

    /**
     * Añade como miembro (no gestor) a cada persona nueva en los proyectos donde aún no lo es.
     *
     * @param  Collection<int, Task>  $tasks
     * @param  array<int, int|null>  $targets
     */
    private function ensureMemberships(Collection $tasks, array $targets): void
    {
        $pairs = [];
        foreach ($tasks as $task) {
            $assignee = $targets[$task->id];
            if ($assignee !== null) {
                $pairs[$task->project_id.':'.$assignee] = [$task->project, $assignee];
            }
        }

        if ($pairs === []) {
            return;
        }

        $existing = DB::table('project_members')
            ->whereIn('project_id', array_unique(array_map(fn (array $pair): int => $pair[0]->id, $pairs)))
            ->whereIn('user_id', array_unique(array_map(fn (array $pair): int => $pair[1], $pairs)))
            ->get(['project_id', 'user_id'])
            ->map(fn (stdClass $row): string => $row->project_id.':'.$row->user_id)
            ->flip();

        foreach ($pairs as $key => [$project, $assignee]) {
            if (! $existing->has($key)) {
                $project->addMember($assignee);
            }
        }
    }
}
