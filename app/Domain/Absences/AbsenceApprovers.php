<?php

namespace App\Domain\Absences;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quién puede aprobar las ausencias de una persona y a quién se avisa de sus solicitudes (D-049,
 * D-020, D-024): los responsables activos de su departamento o, si no tiene departamento o nadie lo
 * dirige, los administradores activos. Nunca la propia persona.
 */
final class AbsenceApprovers
{
    /**
     * @return Collection<int, User>
     */
    public function for(User $owner): Collection
    {
        if ($owner->department_id !== null) {
            $managers = User::query()
                ->active()
                ->whereKeyNot($owner->id)
                ->whereHas('managedDepartments', fn (Builder $department) => $department->whereKey($owner->department_id))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active']);

            if ($managers->isNotEmpty()) {
                return $managers;
            }
        }

        return User::query()
            ->active()
            ->whereKeyNot($owner->id)
            ->role(Role::Admin->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);
    }

    /**
     * El segundo nivel de aprobación (Fase 11, R3; W-067; D-364): RR. HH. (`manage-people`), activo,
     * nunca la propia persona ni un colaborador externo.
     *
     * @return Collection<int, User>
     */
    public function second(User $owner, ?User $except = null): Collection
    {
        return User::query()
            ->active()
            ->whereKeyNot(array_values(array_filter([$owner->id, $except?->id])))
            ->withoutCollaborators()
            ->permission(Permission::ManagePeople->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);
    }
}
