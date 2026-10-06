<?php

namespace App\Domain\Forecast;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién cuenta en la previsión (docs/PLAN-CARGAS.md §6.3, D-283 y D-300):
 *
 * - **La plantilla activa** (admin, responsables y empleados): tiene capacidad, suma a su
 *   departamento y se le asignan horas.
 * - **Los colaboradores externos activos** (D-134) también se pueden asignar (D-300: el equipo es
 *   pequeño y hay colaboradores fijos). Salen aparte en la previsión, con la capacidad de su jornada
 *   o sin capacidad si no la tienen, y no suman a ningún departamento. Nunca ven la previsión.
 * - Los clientes, nunca.
 */
final class ForecastPeople
{
    /**
     * @return Builder<User>
     */
    public static function staff(): Builder
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', User::WEEKLY_ROLES));
    }

    /**
     * Colaboradores externos activos.
     *
     * @return Builder<User>
     */
    public static function collaborators(): Builder
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', Role::Collaborator->value));
    }

    /**
     * A quién se le pueden asignar horas: la plantilla activa y los colaboradores externos activos.
     *
     * @return Builder<User>
     */
    public static function assignables(): Builder
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', [...User::WEEKLY_ROLES, Role::Collaborator->value]));
    }

    /** ¿Se le pueden asignar horas? De la plantilla o colaborador externo, y activa. */
    public static function assignable(User $user): bool
    {
        return $user->isActive() && ($user->writesWeeklies() || $user->isCollaborator());
    }
}
