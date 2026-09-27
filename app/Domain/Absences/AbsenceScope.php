<?php

namespace App\Domain\Absences;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Personas cuyas ausencias gestiona alguien en «Ausencias del equipo» (D-021, D-024, D-049): un
 * admin, todas las internas activas; un responsable, las de los departamentos que dirige (él
 * incluido si es de uno de ellos). Opcionalmente, solo las de un departamento.
 */
final class AbsenceScope
{
    /**
     * @return Builder<User>
     */
    public function people(User $viewer, ?int $departmentId = null): Builder
    {
        return User::query()
            ->active()
            ->internal()
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->whereIn('department_id', $viewer->managedDepartmentIds() ?: [0]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId));
    }

    /**
     * Departamentos por los que puede filtrar: todos (admin) o los que dirige.
     *
     * @return Collection<int, Department>
     */
    public function departments(User $viewer): Collection
    {
        return Department::query()
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->whereKey($viewer->managedDepartmentIds() ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }
}
