<?php

namespace App\Policies;

use App\Domain\Forecast\ForecastAccess;
use App\Enums\ForecastStatus;
use App\Models\ForecastProject;
use App\Models\User;

/**
 * Proyectos previstos (docs/PLAN-CARGAS.md §8 con P4 a; D-284), siempre detrás del módulo
 * `forecast` y nunca para colaboradores externos ni clientes (ForecastAccess):
 * - los ven los admins, los responsables y quien tenga manage-forecast,
 * - los crean, editan, confirman, dan por perdidos, reabren, borran y vinculan quienes tienen
 *   manage-forecast (por defecto, admins y responsables),
 * - crear el proyecto real desde el previsto exige además poder crear proyectos (D-022),
 * - desvincular, solo un admin.
 */
class ForecastProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return ForecastAccess::views($user);
    }

    public function view(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::views($user);
    }

    public function create(User $user): bool
    {
        return ForecastAccess::manages($user);
    }

    /** Editar el previsto y sus asignaciones: abierto o confirmado (vinculado o perdido, congelado). */
    public function update(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status->isEditable();
    }

    public function confirm(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status === ForecastStatus::Open;
    }

    public function lose(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status->counts();
    }

    public function reopen(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status === ForecastStatus::Lost;
    }

    public function delete(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status !== ForecastStatus::Linked;
    }

    /** Vincular con un proyecto existente (además, quien vincula debe gestionar ese proyecto). */
    public function link(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::manages($user) && $forecast->status->counts();
    }

    /** Crear el proyecto real desde el previsto: además, poder crear proyectos (D-022). */
    public function createProject(User $user, ForecastProject $forecast): bool
    {
        return $this->link($user, $forecast) && ($user->isAdmin() || $user->isDepartmentManager());
    }

    public function unlink(User $user, ForecastProject $forecast): bool
    {
        return ForecastAccess::views($user) && $user->isAdmin() && $forecast->status === ForecastStatus::Linked;
    }
}
