<?php

namespace App\Policies;

use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Bolsas de horas (SPEC §8, D-021, D-035):
 * - el % de consumo lo ve cualquier interno,
 * - el detalle por persona, los gestores del proyecto, los responsables y los admins
 *   (los importes, además, con view-financials),
 * - crear, editar, renovar y cerrar, quien gestiona el proyecto,
 * - reabrir una bolsa cerrada no renovada, solo un admin.
 */
class HourBankPolicy
{
    /**
     * Vista global de bolsas (/bolsas).
     */
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('view-hour-banks');
    }

    public function view(User $user, HourBank $hourBank): bool
    {
        return $user->isInternal();
    }

    public function viewBreakdown(User $user, HourBank $hourBank): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager() || $user->isManagerOf($hourBank->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->canManageProject($project);
    }

    public function update(User $user, HourBank $hourBank): bool
    {
        return $user->canManageProject($hourBank->project);
    }

    public function renew(User $user, HourBank $hourBank): bool
    {
        return $this->update($user, $hourBank);
    }

    public function close(User $user, HourBank $hourBank): bool
    {
        return $this->update($user, $hourBank);
    }

    public function reopen(User $user, HourBank $hourBank): bool
    {
        return $user->isAdmin();
    }

    /**
     * Solo una bolsa sin horas, y solo un admin.
     */
    public function delete(User $user, HourBank $hourBank): bool
    {
        return $user->isAdmin() && ! $hourBank->timeEntries()->exists();
    }
}
