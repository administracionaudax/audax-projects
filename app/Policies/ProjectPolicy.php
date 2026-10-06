<?php

namespace App\Policies;

use App\Domain\Forecast\ForecastAccess;
use App\Models\Project;
use App\Models\User;

/**
 * Proyectos (D-021, D-022, D-031, D-032):
 * - los ven todos los internos,
 * - los crean admins y responsables,
 * - los gestionan (ajustes, miembros, gestores y alertas, bolsas, archivar) admins, responsables
 *   y sus gestores,
 * - no se borran: se archivan (D-037).
 * Un colaborador externo (D-134) solo ve los proyectos de los que es miembro (canSeeProject), nunca
 * los gestiona ni ve las horas de todos, y no imputa en los proyectos internos.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isInternal() && $user->canSeeProject($project);
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

    /**
     * Alertas de un gestor del proyecto (D-023): las edita el propio gestor y un admin, las de
     * cualquiera. Solo los gestores tienen alertas.
     */
    public function updateAlerts(User $user, Project $project, User $manager): bool
    {
        return ($user->isAdmin() || $user->id === $manager->id) && $manager->isManagerOf($project);
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
        if ($user->isCollaborator()) {
            return false;
        }

        return $user->isAdmin() || $user->isDepartmentManager() || $user->isManagerOf($project);
    }

    /**
     * Informe del proyecto (/informes/proyectos/{project}, D-044): los mismos que ven todas sus
     * horas (viewAllTime). Un responsable ve en él las horas de su equipo (TimeEntry::visibleTo).
     */
    public function viewReport(User $user, Project $project): bool
    {
        return $this->viewAllTime($user, $project);
    }

    /**
     * Pestaña Planificación (asignaciones del proyecto, D-284): quien lo gestiona (D-022), con el
     * módulo `forecast` visible. Nunca un colaborador externo.
     */
    public function viewPlanning(User $user, Project $project): bool
    {
        return ForecastAccess::plansProject($user, $project);
    }

    /**
     * Crear asignaciones en el proyecto: quien lo gestiona, si no está archivado (D-284).
     */
    public function manageAllocations(User $user, Project $project): bool
    {
        return ForecastAccess::allocatesProject($user, $project);
    }

    /**
     * Imputar horas propias en el proyecto: miembros, o cualquier interno si es interno (D-033).
     */
    public function logTime(User $user, Project $project): bool
    {
        if ($user->isCollaborator()) {
            return $project->acceptsTime() && ! $project->isInternal() && $user->isMemberOf($project);
        }

        return $user->isInternal()
            && $project->acceptsTime()
            && ($project->isInternal() || $user->isMemberOf($project));
    }
}
