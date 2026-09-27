<?php

namespace App\Http\Controllers\Workload;

use App\Domain\Projects\ProjectMembership;
use App\Domain\Tasks\TaskNotifier;
use App\Domain\Tasks\TaskWriter;
use App\Domain\Workload\WorkloadScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workload\UpdateWorkloadTaskRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Reasignar y replanificar una tarea desde la vista Carga (panel de una celda y bandejas, D-052):
 * - primero las reglas de Tareas: TaskPolicy::update (D-031),
 * - después el alcance de la vista: la tarea tiene que estar en SU carga (de alguien de su alcance,
 *   sin asignar en uno de sus departamentos o de un proyecto que gestiona): un empleado no toca las
 *   tareas de otros,
 * - cambiar el responsable solo lo hacen el admin, los responsables (dentro de su equipo) y los
 *   gestores (entre los miembros de su proyecto),
 * - el cambio lo hace TaskWriter (validaciones, auditoría y avisos). Si el nuevo responsable no es
 *   miembro del proyecto, pasa a serlo para poder imputar (como en la baja de una persona, D-038),
 *   en la misma transacción: o se guardan las dos cosas o ninguna (y sin avisos),
 * - vuelve a /carga con los mismos filtros y la celda abierta: la matriz se recalcula.
 */
class WorkloadTaskController extends Controller
{
    public function __construct(
        private readonly TaskWriter $writer,
        private readonly ProjectMembership $membership,
        private readonly TaskNotifier $notifier,
    ) {}

    public function update(UpdateWorkloadTaskRequest $request, Task $task): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $task->loadMissing(['project', 'hourBank:id,department_id', 'type:id,department_id']);

        Gate::authorize('update', $task);

        $scope = new WorkloadScope($user);
        Gate::allowIf($scope->reaches($task), __('workload.errors.out_of_scope'));

        $data = $request->validated();
        $assignee = $this->newAssignee($scope, $task, $data);

        // Todo o nada: el alta como miembro y el cambio de la tarea van en una transacción, y los
        // avisos (asignación) solo salen si las dos cosas se guardan.
        $joined = $this->notifier->capture(fn (): bool => DB::transaction(function () use ($user, $task, $data, $assignee): bool {
            // Dentro de la transacción: si otra petición acaba de darlo de alta, no se repite.
            $joins = $assignee !== null && ! $task->project->isInternal() && ! $task->project->hasMember($assignee);

            if ($joins) {
                $this->membership->add($task->project, $assignee, false, $user);
            }

            $this->writer->update($user, $task, $data);

            return $joins;
        }));

        $message = $joined && $assignee !== null
            ? __('workload.flash.added_member', ['task' => $task->title, 'name' => $assignee->name, 'project' => $task->project->code])
            : __('workload.flash.updated', ['task' => $task->title]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Si cambia el responsable: quién puede hacerlo y a quién (D-052). Devuelve el nuevo
     * responsable (null si no cambia o se deja sin asignar).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function newAssignee(WorkloadScope $scope, Task $task, array $data): ?User
    {
        if (! array_key_exists('assignee_user_id', $data)) {
            return null;
        }

        $newId = is_numeric($data['assignee_user_id']) ? (int) $data['assignee_user_id'] : null;

        if ($newId === $task->assignee_user_id) {
            return null;
        }

        Gate::allowIf($scope->canReassign($task), __('workload.errors.cannot_reassign'));

        if ($newId === null) {
            return null;
        }

        $members = $scope->viewer->isManagerOf($task->project_id)
            ? DB::table('project_members')->where('project_id', $task->project_id)->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        if (! in_array($newId, $scope->assigneeOptions($task, array_values($members)), true)) {
            throw ValidationException::withMessages(['assignee_user_id' => __('workload.errors.assignee_out_of_scope')]);
        }

        $assignee = User::query()->active()->internal()->find($newId);

        if ($assignee === null) {
            throw ValidationException::withMessages(['assignee_user_id' => __('tasks.errors.assignee_invalid')]);
        }

        return $assignee;
    }
}
