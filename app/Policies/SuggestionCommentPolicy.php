<?php

namespace App\Policies;

use App\Models\SuggestionComment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Comentarios de las sugerencias (F-165 y F-166): los edita su autor; los borra su autor o quien
 * gestiona; reacciona cualquiera que use la Weekly.
 */
class SuggestionCommentPolicy
{
    public function update(User $user, SuggestionComment $comment): bool
    {
        return $comment->author_id === $user->id && Gate::forUser($user)->allows('use-weeklies');
    }

    public function delete(User $user, SuggestionComment $comment): bool
    {
        return $this->update($user, $comment) || Gate::forUser($user)->allows('manage-weeklies');
    }

    /** En un tablero oculto (D-210) ya no se reacciona (D-226). */
    public function react(User $user, SuggestionComment $comment): bool
    {
        return Gate::forUser($user)->allows('use-weeklies')
            && SuggestionPostPolicy::boardIsActive($comment->post);
    }
}
