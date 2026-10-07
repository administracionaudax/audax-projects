<?php

namespace App\Policies;

use App\Domain\Absences\AbsenceService;
use App\Domain\Absences\LeaveMode;
use App\Domain\People\PeopleAccess;
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
 *   aprobarlas anula una aprobada (queda en la auditoría),
 * - quien puede aprobarlas modifica una aprobada de otra persona (acortar una baja que termina
 *   antes, por ejemplo), nunca la suya: como al revisar.
 * Los clientes nunca llegan aquí (middleware internal) y un desactivado no puede nada (Gate::before).
 *
 * Fase 11, R3 (D-364, D-365 y D-370), con el módulo `people` visible para quien actúa:
 * - RR. HH. (`manage-people`) aprueba las de toda la plantilla, como un admin (PLAN §9),
 * - en el segundo nivel (vacaciones, si se activa), cuando el responsable ya ha dado el primero,
 *   solo decide RR. HH.,
 * - la persona pide cancelar una aprobada que ya ha empezado; la decide quien aprueba sus ausencias.
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
        return $user->isAdmin() || $user->isDepartmentManager() || self::hr($user);
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

        return $user->isAdmin() || self::hr($user) || $user->supervises($target);
    }

    public function review(User $user, Absence $absence): bool
    {
        if ($user->id === $absence->user_id) {
            return false;
        }

        // Ya tiene el primer nivel: solo falta RR. HH.
        if ($absence->status === AbsenceStatus::Requested && $absence->first_approved_at !== null
            && AbsenceService::needsSecondLevel($absence, $user)) {
            return self::hr($user);
        }

        return $this->approves($user, $absence);
    }

    /**
     * «Pedir cancelación» (R3): la persona, de una suya aprobada que ya ha empezado (la que no ha
     * empezado la cancela sin más) y sin otra petición pendiente.
     */
    public function requestCancellation(User $user, Absence $absence): bool
    {
        return $user->id === $absence->user_id
            && LeaveMode::on($user)
            && $absence->status === AbsenceStatus::Approved
            && ! $absence->cancellationPending()
            && $absence->start_date->toDateString() <= LocalTime::todayString();
    }

    /** Aceptar o rechazar la cancelación pedida: quien aprueba sus ausencias, nunca ella misma. */
    public function decideCancellation(User $user, Absence $absence): bool
    {
        return $user->id !== $absence->user_id
            && $absence->status === AbsenceStatus::Approved
            && $absence->cancellationPending()
            && $this->approves($user, $absence);
    }

    /**
     * Modificar una ausencia aprobada (tipo, fechas, parte del día y notas): quien puede aprobarla,
     * nunca la propia persona.
     */
    public function update(User $user, Absence $absence): bool
    {
        return $absence->status === AbsenceStatus::Approved
            && $user->id !== $absence->user_id
            && $this->approves($user, $absence);
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
        if ($user->isAdmin() || self::hr($user)) {
            return true;
        }

        $department = $absence->relationLoaded('user')
            ? $absence->user->department_id
            : User::query()->whereKey($absence->user_id)->value('department_id');

        return $department !== null && $user->managesDepartment((int) $department);
    }

    /** RR. HH. (`manage-people`) con el módulo visible: aprueba como un admin (R3). */
    private static function hr(User $user): bool
    {
        return LeaveMode::on($user) && PeopleAccess::managesAll($user);
    }
}
