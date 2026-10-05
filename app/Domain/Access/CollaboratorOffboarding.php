<?php

namespace App\Domain\Access;

use App\Domain\Integrations\Google\GoogleDisconnector;
use App\Domain\Integrations\Google\GoogleDisconnectReason;
use App\Enums\ConversationType;
use App\Enums\Role;
use App\Models\ActiveTimer;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lo que deja atrás un colaborador externo cuando deja de ver un proyecto (D-134): al sacarlo de
 * él, al mover una tarea a un proyecto del que no es miembro y al pasar a colaborador.
 * En las tareas de esos proyectos:
 * - deja de seguirlas (task_watchers),
 * - si era su responsable, se quedan sin responsable,
 * - si tenía el temporizador en marcha en una de ellas, se descarta sin imputar.
 * Al pasar a colaborador, además, deja de ser co-gestor de sus proyectos y sale (left_at) de sus
 * directas y sus grupos: su histórico se conserva, pero ya no los ve. Y se desconecta su cuenta de
 * Google (D-142).
 * Las personas que aparecen en el panel de la tarea por su histórico (autores de comentarios y
 * creador) se mantienen: es una decisión de producto.
 */
final class CollaboratorOffboarding
{
    public function __construct(private readonly GoogleDisconnector $google) {}

    /**
     * Ha salido del proyecto (ProjectMembership::remove).
     */
    public function leftProject(User $user, int $projectId): void
    {
        if (! $user->isCollaborator()) {
            return;
        }

        $this->release($user->id, Task::query()->withTrashed()->select('id')->where('project_id', $projectId));
    }

    /**
     * Tareas que han pasado a $target (TaskMover): los colaboradores que las seguían o eran sus
     * responsables y no son miembros del destino las sueltan.
     *
     * @param  list<int>  $taskIds  la tarea y sus subtareas
     */
    public function tasksMoved(array $taskIds, Project $target): void
    {
        if ($taskIds === []) {
            return;
        }

        $userIds = User::query()
            ->role(Role::Collaborator->value)
            ->whereNotIn('users.id', DB::table('project_members')->select('user_id')->where('project_id', $target->id))
            ->where(fn (Builder $query) => $query
                ->whereIn('users.id', Task::query()->withTrashed()->select('assignee_user_id')->whereKey($taskIds)->whereNotNull('assignee_user_id'))
                ->orWhereIn('users.id', DB::table('task_watchers')->select('user_id')->whereIn('task_id', $taskIds)))
            ->pluck('users.id');

        foreach ($userIds as $userId) {
            $this->release((int) $userId, $taskIds);
        }
    }

    /**
     * Ha pasado a colaborador (administración o importación): suelta las tareas de los proyectos
     * de los que no es miembro, deja de ser co-gestor y sale de sus directas y grupos.
     */
    public function becameCollaborator(User $user): void
    {
        DB::table('project_members')->where('user_id', $user->id)->where('is_manager', true)->update(['is_manager' => false]);

        $this->release($user->id, Task::query()->withTrashed()->select('id')
            ->whereNotIn('project_id', DB::table('project_members')->select('project_id')->where('user_id', $user->id)));

        ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->whereHas('conversation', fn (Builder $query) => $query->whereIn('type', [ConversationType::Direct->value, ConversationType::Group->value]))
            ->update(['left_at' => now()]);

        // Un colaborador externo no tiene Integraciones (D-134, D-142): su cuenta de Google se
        // desconecta y el token se revoca desde la cola.
        $this->google->disconnect($user, GoogleDisconnectReason::BecameCollaborator);

        User::forgetMemberships();
    }

    /**
     * @param  Builder<Task>|list<int>  $taskIds
     */
    private function release(int $userId, Builder|array $taskIds): void
    {
        DB::table('task_watchers')->where('user_id', $userId)->whereIn('task_id', $taskIds)->delete();

        // Una a una, para que el cambio de responsable quede en la actividad de la tarea.
        Task::query()->withTrashed()->whereIn('id', $taskIds)->where('assignee_user_id', $userId)->get()
            ->each(fn (Task $task) => $task->forceFill(['assignee_user_id' => null])->save());

        ActiveTimer::query()->whereKey($userId)->whereIn('task_id', $taskIds)->delete();
    }
}
