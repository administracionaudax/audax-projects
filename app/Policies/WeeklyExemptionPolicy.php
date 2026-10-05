<?php

namespace App\Policies;

use App\Enums\WeeklyExemptionReason;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Illuminate\Support\Facades\Gate;

/**
 * Exenciones (D-147, D-150 y D-151), solo con la semana activa (cerrada, la foto no se toca):
 * - eximir a alguien a mano (F-038): `manage-weeklies`,
 * - quitar una exención: quien gestiona o la propia persona (F-053),
 * - renunciar a la exención que da una ausencia (waived, F-053): la propia persona.
 */
class WeeklyExemptionPolicy
{
    public function create(User $user, WeeklyCycle $cycle): bool
    {
        return Gate::forUser($user)->allows('manage-weeklies') && $cycle->isActive();
    }

    /** Renunciar a la exención propia por ausencia, para poder enviar. */
    public function waive(User $user, WeeklyCycle $cycle): bool
    {
        return Gate::forUser($user)->allows('use-weeklies') && $cycle->isActive();
    }

    public function delete(User $user, WeeklyExemption $exemption): bool
    {
        if ($exemption->reason === WeeklyExemptionReason::Absence) {
            // La foto de una ausencia solo existe con la semana cerrada: no se toca.
            return false;
        }

        $active = WeeklyCycle::query()->whereKey($exemption->weekly_cycle_id)->active()->exists();

        return $active && ($exemption->user_id === $user->id
            ? Gate::forUser($user)->allows('use-weeklies')
            : Gate::forUser($user)->allows('manage-weeklies'));
    }
}
