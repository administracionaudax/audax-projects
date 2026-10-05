<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\Gate;

/**
 * Weeklies de las personas (D-147 y D-150):
 * - cada interno de plantilla escribe la suya mientras la semana está activa, también fuera de
 *   plazo (F-052); con la semana cerrada, solo lectura,
 * - las enviadas las ven todos los que usan la Weekly (como en WeeklySync: «reportes originales»,
 *   F-078); los borradores, solo su autor.
 * Que no esté exenta (F-054) y que le toque enviar lo comprueba WeeklySubmissionWriter.
 */
class WeeklySubmissionPolicy
{
    /** Escribir (borrador o envío) la weekly propia de esa semana. */
    public function create(User $user, WeeklyCycle $cycle): bool
    {
        return Gate::forUser($user)->allows('use-weeklies') && $cycle->isActive();
    }

    public function view(User $user, WeeklySubmission $submission): bool
    {
        if ($submission->user_id === $user->id) {
            return true;
        }

        return $submission->isSubmitted() && Gate::forUser($user)->allows('use-weeklies');
    }

    public function update(User $user, WeeklySubmission $submission): bool
    {
        return $submission->user_id === $user->id
            && Gate::forUser($user)->allows('use-weeklies')
            && WeeklyCycle::query()->whereKey($submission->weekly_cycle_id)->active()->exists();
    }

    public function delete(User $user, WeeklySubmission $submission): bool
    {
        return false;
    }
}
