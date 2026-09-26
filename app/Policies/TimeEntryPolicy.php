<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * Entradas de horas (SPEC §7, D-021, D-034, D-036). Las reglas de dominio (semana abierta,
 * bolsa, 24 h…) las aplica TimeEntryRules; aquí solo quién puede actuar sobre quién.
 */
class TimeEntryPolicy
{
    public function view(User $user, TimeEntry $entry): bool
    {
        return $user->id === $entry->user_id
            || $user->isAdmin()
            || $user->supervises($entry->user)
            || $user->isManagerOf($entry->project_id);
    }

    /**
     * Imputar en nombre de otra persona (SPEC §7, D-036): gestores en sus proyectos, responsables
     * para su equipo y admins para cualquiera. Queda en la auditoría (created_by).
     */
    public function logTimeFor(User $user, User $target, Project $project): bool
    {
        return $user->id === $target->id
            || $user->isAdmin()
            || $user->supervises($target)
            || $user->isManagerOf($project);
    }

    /**
     * Las bloqueadas, solo un admin (SPEC §7).
     */
    public function update(User $user, TimeEntry $entry): bool
    {
        if ($entry->isLocked()) {
            return $user->isAdmin();
        }

        return $user->id === $entry->user_id
            || $user->isAdmin()
            || $user->supervises($entry->user)
            || $user->isManagerOf($entry->project_id);
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
