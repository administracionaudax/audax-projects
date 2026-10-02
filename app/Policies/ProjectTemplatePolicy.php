<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;

/**
 * Plantillas de proyecto (SPEC §4.3 y §14, D-022, D-058):
 * - las ven y las aplican al crear un proyecto quienes crean proyectos: admins y responsables,
 * - en un proyecto existente (Ajustes) las aplica y guarda el proyecto como plantilla quien lo
 *   gestiona (admin, responsables y sus gestores),
 * - las crea, edita, desactiva, borra (papelera) y recupera solo un admin (/admin/plantillas).
 * Un cliente nunca llega: el middleware internal lo lleva a su portal.
 */
class ProjectTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->createsProjects($user);
    }

    public function view(User $user, ProjectTemplate $template): bool
    {
        return $this->createsProjects($user);
    }

    /**
     * Aplicar al crear un proyecto: una plantilla activa y no borrada, quien crea proyectos.
     */
    public function apply(User $user, ProjectTemplate $template): bool
    {
        return $this->createsProjects($user) && $this->usable($template);
    }

    /**
     * Aplicar a un proyecto existente (Ajustes): añade tareas sin tocar las que ya hay.
     */
    public function applyToProject(User $user, ProjectTemplate $template, Project $project): bool
    {
        return $this->usable($template) && $user->isInternal() && $user->canManageProject($project);
    }

    /**
     * Ver las plantillas activas que se pueden aplicar desde los Ajustes de un proyecto.
     */
    public function listForProject(User $user, Project $project): bool
    {
        return $user->isInternal() && $user->canManageProject($project);
    }

    /**
     * «Guardar como plantilla» desde los Ajustes de un proyecto.
     */
    public function capture(User $user, Project $project): bool
    {
        return $user->isInternal() && $user->canManageProject($project);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ProjectTemplate $template): bool
    {
        return $user->isAdmin() && ! $template->trashed();
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $user->isAdmin() && ! $template->trashed();
    }

    public function restore(User $user, ProjectTemplate $template): bool
    {
        return $user->isAdmin() && $template->trashed();
    }

    /**
     * Nunca se borran del todo desde la app: la papelera las conserva.
     */
    public function forceDelete(User $user, ProjectTemplate $template): bool
    {
        return false;
    }

    public function export(User $user, ProjectTemplate $template): bool
    {
        return $user->isAdmin();
    }

    private function createsProjects(User $user): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager();
    }

    private function usable(ProjectTemplate $template): bool
    {
        return $template->is_active && ! $template->trashed();
    }
}
