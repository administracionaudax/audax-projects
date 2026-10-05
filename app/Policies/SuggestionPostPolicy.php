<?php

namespace App\Policies;

use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Sugerencias (F-159 a F-168, D-147):
 * - las ven, crean, votan y comentan los internos de plantilla (`use-weeklies`),
 * - las edita o borra su autor o quien gestiona (F-164),
 * - el estado, la nota oficial y el orden del roadmap, solo quien gestiona (F-167 y F-168), igual
 *   que los tableros y las categorías (F-160).
 */
class SuggestionPostPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('use-weeklies');
    }

    public function view(User $user, SuggestionPost $post): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function vote(User $user, SuggestionPost $post): bool
    {
        return $this->viewAny($user);
    }

    public function comment(User $user, SuggestionPost $post): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SuggestionPost $post): bool
    {
        return $this->viewAny($user) && ($post->author_id === $user->id || $this->manages($user));
    }

    public function delete(User $user, SuggestionPost $post): bool
    {
        return $this->update($user, $post);
    }

    /** Cambiar el estado con nota oficial y mover en el roadmap. */
    public function moderate(User $user, SuggestionPost $post): bool
    {
        return $this->manages($user);
    }

    /** Tableros y categorías. */
    public function manageBoards(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-weeklies');
    }
}
