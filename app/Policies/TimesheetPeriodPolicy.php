<?php

namespace App\Policies;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetPeriod;
use App\Models\User;

/**
 * Flujo de la semana (SPEC §7, D-020, D-034):
 * - la envía y la retira (si aún no está revisada) su dueño,
 * - la aprueba o devuelve un responsable de su departamento o un admin (nunca uno mismo: las
 *   semanas de responsables y admins se aprueban solas al enviarlas),
 * - una aprobada la reabre quien puede aprobarla; una bloqueada, solo un admin.
 */
class TimesheetPeriodPolicy
{
    public function view(User $user, TimesheetPeriod $period): bool
    {
        return $user->canSeeHoursOf($period->user);
    }

    public function submit(User $user, TimesheetPeriod $period): bool
    {
        return $user->id === $period->user_id && $period->status->isEditable();
    }

    public function withdraw(User $user, TimesheetPeriod $period): bool
    {
        return $user->id === $period->user_id && $period->status === TimesheetStatus::Submitted;
    }

    public function review(User $user, TimesheetPeriod $period): bool
    {
        if ($user->id === $period->user_id) {
            return false;
        }

        return $user->isAdmin() || $user->supervises($period->user);
    }

    public function reopen(User $user, TimesheetPeriod $period): bool
    {
        return match ($period->status) {
            TimesheetStatus::Approved => $user->isAdmin() || ($user->id !== $period->user_id && $user->supervises($period->user)),
            TimesheetStatus::Locked => $user->isAdmin(),
            default => false,
        };
    }
}
