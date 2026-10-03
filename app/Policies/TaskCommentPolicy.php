<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

/**
 * Comentarios: los edita y borra su autor (o un admin), siempre que vea el proyecto de la tarea.
 * Un colaborador externo que ha salido del proyecto ya no toca sus comentarios de allí (D-134).
 */
class TaskCommentPolicy
{
    public function update(User $user, TaskComment $comment): bool
    {
        return $comment->user_id === $user->id && $this->seesProject($user, $comment);
    }

    public function delete(User $user, TaskComment $comment): bool
    {
        return ($comment->user_id === $user->id || $user->isAdmin()) && $this->seesProject($user, $comment);
    }

    private function seesProject(User $user, TaskComment $comment): bool
    {
        // La plantilla ve todos los proyectos: sin consulta.
        if ($user->visibleProjectIds() === null) {
            return true;
        }

        // Con la tarea borrada también (sus comentarios siguen siendo de ese proyecto).
        $projectId = Task::query()->withTrashed()->whereKey($comment->task_id)->value('project_id');

        return $projectId !== null && $user->canSeeProject((int) $projectId);
    }
}
