<?php

namespace App\Policies;

use App\Models\Dictation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Dictados (D-152): solo los ve y los usa quien los grabó.
 */
class DictationPolicy
{
    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('use-weeklies');
    }

    public function view(User $user, Dictation $dictation): bool
    {
        return $dictation->user_id === $user->id;
    }
}
