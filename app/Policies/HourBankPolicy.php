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
 * - crear, editar, renovar y cerrar, quien gestiona el proyecto; crear y renovar, además, solo
 *   si el proyecto admite horas (no archivado, como TaskPolicy::create): una bolsa nueva en un
 *   proyecto archivado nunca podría recibir tareas ni horas,
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

    /**
     * PDF de consumo de la bolsa (D-045): lleva el detalle de las entradas con la persona, así que
     * exige ver el detalle por persona (viewBreakdown). Un responsable que no gestiona el proyecto
     * solo verá en él las horas de su equipo (el PDF lo avisa).
     */
    public function downloadPdf(User $user, HourBank $hourBank): bool
    {
        return $this->viewBreakdown($user, $hourBank);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->acceptsTime() && $user->canManageProject($project);
    }

    public function update(User $user, HourBank $hourBank): bool
    {
        return $user->canManageProject($hourBank->project);
    }

    public function renew(User $user, HourBank $hourBank): bool
    {
        return $hourBank->project->acceptsTime() && $this->update($user, $hourBank);
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
