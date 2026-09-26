<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Proyectos (D-021, D-022, D-031, D-032):
 * - los ven todos los internos,
 * - los crean admins y responsables,
 * - los gestionan (ajustes, miembros, gestores y alertas, bolsas, archivar) admins, responsables
 *   y sus gestores,
 * - no se borran: se archivan (D-037).
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isInternal();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canManageProject($project);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $user->canManageProject($project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $user->canManageProject($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Ver todas las entradas de horas del proyecto (pestaña Horas, D-021): gestores, responsables
     * (con las de su equipo; el filtro fino lo aplica TimeEntry::visibleTo) y admins.
     */
    public function viewAllTime(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager() || $user->isManagerOf($project);
    }

    /**
     * Imputar horas propias en el proyecto: miembros, o cualquier interno si es interno (D-033).
     */
    public function logTime(User $user, Project $project): bool
    {
        return $user->isInternal()
            && $project->acceptsTime()
            && ($project->isInternal() || $user->isMemberOf($project));
    }
}
