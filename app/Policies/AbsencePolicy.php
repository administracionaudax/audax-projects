<?php

namespace App\Policies;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Ausencias (SPEC §5, D-049, D-020, D-021, D-024):
 * - cada persona interna ve y solicita las suyas,
 * - las aprueba o rechaza un responsable de su departamento o un admin, nunca uno mismo (las de
 *   responsables y admins se aprueban solas al solicitarlas),
 * - un admin o un responsable registra una ausencia ya aprobada de alguien de su ámbito,
 * - la persona cancela las suyas solicitadas o las aprobadas que aún no han empezado; quien puede
 *   aprobarlas anula una aprobada (queda en la auditoría).
 * Los clientes nunca llegan aquí (middleware internal) y un desactivado no puede nada (Gate::before).
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    /**
     * «Ausencias del equipo»: responsables y admins, como las aprobaciones de horas (D-020).
     */
    public function viewTeam(User $user): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager();
    }

    public function view(User $user, Absence $absence): bool
    {
        return $user->id === $absence->user_id || $this->approves($user, $absence);
    }

    public function create(User $user): bool
    {
        return $user->isInternal();
    }

    /**
     * Registrar una ausencia ya aprobada de $target (una baja, por ejemplo).
     */
    public function register(User $user, User $target): bool
    {
        if (! $target->isInternal()) {
            return false;
        }

        return $user->isAdmin() || $user->supervises($target);
    }

    public function review(User $user, Absence $absence): bool
    {
        return $user->id !== $absence->user_id && $this->approves($user, $absence);
    }

    public function cancel(User $user, Absence $absence): bool
    {
        if ($user->id === $absence->user_id) {
            return match ($absence->status) {
                AbsenceStatus::Requested => true,
                AbsenceStatus::Approved => $absence->start_date->toDateString() > LocalTime::todayString(),
                default => false,
            };
        }

        return $absence->status === AbsenceStatus::Approved && $this->approves($user, $absence);
    }

    /**
     * ¿Puede aprobar las ausencias de la persona? Un admin, o un responsable de su departamento
     * (el departamento se lee de la persona cargada con la ausencia o, si no, con una consulta).
     */
    private function approves(User $user, Absence $absence): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $department = $absence->relationLoaded('user')
            ? $absence->user->department_id
            : User::query()->whereKey($absence->user_id)->value('department_id');

        return $department !== null && $user->managesDepartment((int) $department);
    }
}
