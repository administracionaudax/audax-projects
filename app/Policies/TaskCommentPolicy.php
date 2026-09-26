<?php

namespace App\Policies;

use App\Models\TaskComment;
use App\Models\User;

/**
 * Comentarios: los edita y borra su autor (o un admin).
 */
class TaskCommentPolicy
{
    public function update(User $user, TaskComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, TaskComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->isAdmin();
    }
}
