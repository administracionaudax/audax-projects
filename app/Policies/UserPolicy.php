<?php

namespace App\Policies;

use App\Models\User;

/**
 * Informe de una persona (D-021, D-044, Fase 2): la propia persona, quien la supervisa (responsable
 * de su departamento) o un administrador. Un gestor de proyecto no ve el informe de las personas
 * de sus proyectos (solo sus horas en ellos, en el informe detallado). Los usuarios del portal
 * (clientes) no tienen informe. La gestión de usuarios sigue en el permiso manage-users.
 */
class UserPolicy
{
    /**
     * Índice de informes (/informes): cualquier usuario interno; como mínimo ve su propio informe
     * y el detallado con sus horas (D-044).
     */
    public function viewReports(User $viewer): bool
    {
        return $viewer->isInternal();
    }

    public function viewReport(User $viewer, User $person): bool
    {
        if (! $viewer->isInternal() || ! $person->isInternal()) {
            return false;
        }

        return $viewer->id === $person->id || $viewer->isAdmin() || $viewer->supervises($person);
    }
}
