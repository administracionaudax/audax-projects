<?php

namespace App\Domain\Gantt;

use App\Models\User;

/**
 * Quién puede mover y enlazar las tareas de cada proyecto del Gantt (D-060): la MISMA regla que
 * TaskPolicy::update (miembro del proyecto o quien lo gestiona: admin, responsable o gestor, D-031),
 * pero calculada para muchos proyectos a la vez con dos consultas como mucho, en lugar de una por
 * proyecto (el Gantt multiproyecto enseña hasta 60). tests/Feature/Gantt comprueba que coincide
 * con la política para cada rol.
 */
final class GanttAccess
{
    /**
     * @param  list<int>  $projectIds
     * @return array<int, bool> id del proyecto → puede editar sus tareas
     */
    public function editable(User $user, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        if ($user->isClient()) {
            return array_fill_keys($projectIds, false);
        }

        if ($user->isAdmin() || $user->isDepartmentManager()) {
            return array_fill_keys($projectIds, true);
        }

        $allowed = array_flip($user->managedProjectIds());

        $memberOf = $user->projects()
            ->whereIn('projects.id', $projectIds)
            ->pluck('projects.id');

        foreach ($memberOf as $id) {
            $allowed[(int) $id] = true;
        }

        $editable = [];
        foreach ($projectIds as $id) {
            $editable[$id] = isset($allowed[$id]);
        }

        return $editable;
    }
}
