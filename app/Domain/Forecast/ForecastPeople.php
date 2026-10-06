<?php

namespace App\Domain\Forecast;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién cuenta en la previsión (docs/PLAN-CARGAS.md §6.3, D-283): la plantilla activa (admin,
 * responsables y empleados). Ni los colaboradores externos (D-134) ni los clientes: no tienen
 * capacidad en la agencia, no se les asignan horas y no ven la previsión.
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

    /** ¿Se le pueden asignar horas? De la plantilla y activa. */
    public static function assignable(User $user): bool
    {
        return $user->isActive() && $user->writesWeeklies();
    }
}
