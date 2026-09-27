<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\User;

/**
 * Tareas recurrentes (SPEC §4.3 y §14, D-059):
 * - las reglas de un proyecto las gestiona quien gestiona el proyecto (admin, responsables y sus
 *   gestores), desde su pestaña Ajustes,
 * - la vista global (/admin/tareas-recurrentes) es solo del admin.
 * Un cliente nunca llega: el middleware internal lo lleva a su portal.
 */
class RecurringTaskRulePolicy
{
    /**
     * Vista global de todas las reglas.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ver y gestionar las reglas de un proyecto.
     */
    public function manage(User $user, Project $project): bool
    {
        return $user->isInternal() && $user->canManageProject($project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->manage($user, $project);
    }

    public function view(User $user, RecurringTaskRule $rule): bool
    {
        return $this->manage($user, $rule->project);
    }

    public function update(User $user, RecurringTaskRule $rule): bool
    {
        return $this->manage($user, $rule->project);
    }

    public function delete(User $user, RecurringTaskRule $rule): bool
    {
        return $this->manage($user, $rule->project);
    }
}
