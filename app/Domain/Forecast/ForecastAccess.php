<?php

namespace App\Domain\Forecast;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

/**
 * Quién ve y quién toca la previsión (docs/PLAN-CARGAS.md §8 con P4 a y P8 b; D-284):
 *
 * - Todo va detrás del módulo `forecast` (apagado, solo los admins en modo de prueba, D-239) y solo
 *   para la plantilla activa: nunca un colaborador externo (D-134) ni un cliente.
 * - **Ver la previsión global** (/prevision, los previstos, su impacto): los admins y todos los
 *   responsables (ven a toda la plantilla, P4), y quien tenga `manage-forecast`.
 * - **Crear y editar previstos y sus asignaciones**: el permiso `manage-forecast` (por defecto, los
 *   admins y los responsables).
 * - **Asignaciones de un proyecto real** (pestaña Planificación): quien gestiona el proyecto (admin,
 *   responsables y sus gestores, D-022), en un proyecto no archivado.
 * - **Su propia carga** (P8 b): cualquier persona de plantilla ve todas sus asignaciones, también las
 *   de previstos posibles. Los empleados no ven la previsión de los demás.
 */
final class ForecastAccess
{
    /** ¿Está la previsión a su alcance? Plantilla activa con el módulo visible para ella. */
    public static function enabledFor(?User $user): bool
    {
        return $user !== null
            && $user->isActive()
            && ! $user->isCollaborator()
            && $user->writesWeeklies()
            && AppModules::visibleTo($user, AppModule::Forecast);
    }

    /** ¿Ve la previsión global? Admins, responsables y quien tenga manage-forecast. */
    public static function views(?User $user): bool
    {
        return self::enabledFor($user) && $user !== null
            && ($user->isAdmin() || $user->isDepartmentManager() || self::hasPermission($user));
    }

    /** ¿Crea y edita proyectos previstos y sus asignaciones? */
    public static function manages(?User $user): bool
    {
        return self::enabledFor($user) && $user !== null && self::hasPermission($user);
    }

    /** ¿Ve la pestaña Planificación del proyecto? Quien lo gestiona (D-022). */
    public static function plansProject(?User $user, Project $project): bool
    {
        return self::enabledFor($user) && $user !== null && $user->canManageProject($project);
    }

    /** ¿Crea y edita asignaciones del proyecto real? Quien lo gestiona, si no está archivado. */
    public static function allocatesProject(?User $user, Project $project): bool
    {
        return self::plansProject($user, $project) && $project->acceptsTime() && ! $project->trashed();
    }

    private static function hasPermission(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ManageForecast->value);
    }
}
