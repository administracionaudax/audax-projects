<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Informes por departamento (D-044, Fase 2). La gestión de departamentos sigue en el permiso
 * manage-settings (admin): esta política solo decide qué informes se ven.
 * - Dashboard de dirección: los administradores (toda la agencia) y los responsables que dirigen
 *   algún departamento (limitado a los suyos, D-024).
 * - Dashboard de un departamento: los administradores, cualquiera; un responsable, los suyos.
 */
class DepartmentPolicy
{
    public function viewDirectionReport(User $user): bool
    {
        return $user->isInternal() && ($user->isAdmin() || $user->managedDepartmentIds() !== []);
    }

    public function viewReport(User $user, Department $department): bool
    {
        return $user->isInternal() && ($user->isAdmin() || $user->managesDepartment($department));
    }
}
