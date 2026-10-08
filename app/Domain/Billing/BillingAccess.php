<?php

namespace App\Domain\Billing;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Quién ve qué de la Facturación (Fase 12, F1; D-391):
 * - Todo va detrás del módulo `billing` (apagado; en modo de prueba, solo los admins, D-239) y solo
 *   para la plantilla interna activa: nunca un colaborador externo (D-134) ni un cliente.
 * - **Importes, facturas, cobros, contactos de Holded y datos fiscales** (`view-billing`): quien tiene
 *   view-financials (los admins, por defecto).
 * - **«Vendido frente a real»** (`view-sold-vs-actual`): además, quien ve las bolsas (responsables y
 *   gestores de proyecto, D-035), pero solo en horas y, un gestor, solo de sus proyectos.
 * - **Sincronizar ahora** (`sync-holded`): los admins.
 */
final class BillingAccess
{
    public static function uses(?User $user): bool
    {
        return $user !== null
            && $user->isActive()
            && $user->isInternal()
            && ! $user->isCollaborator()
            && AppModules::visibleTo($user, AppModule::Billing);
    }

    /** Facturas, cobros, importes y datos fiscales: con view-financials. */
    public static function viewsBilling(?User $user): bool
    {
        return self::uses($user) && $user !== null && Gate::forUser($user)->allows('view-financials');
    }

    /** El informe «Vendido frente a real» (en horas sin view-financials). */
    public static function viewsSoldVsActual(?User $user): bool
    {
        return self::uses($user) && $user !== null
            && (Gate::forUser($user)->allows('view-financials') || Gate::forUser($user)->allows('view-hour-banks'));
    }

    public static function syncs(?User $user): bool
    {
        return self::uses($user) && $user !== null && $user->isAdmin();
    }

    /**
     * Proyectos que entran en el informe para $user: null = todos (admins, responsables y
     * view-financials); si no, los que gestiona.
     *
     * @return list<int>|null
     */
    public static function projectScope(User $user): ?array
    {
        if ($user->isAdmin() || $user->isDepartmentManager() || Gate::forUser($user)->allows('view-financials')) {
            return null;
        }

        return $user->managedProjectIds();
    }

    /** ¿Ve el informe de este proyecto (pestaña Facturación)? */
    public static function viewsProject(?User $user, Project $project): bool
    {
        if (! self::viewsSoldVsActual($user) || $user === null || $project->isInternal()) {
            return false;
        }

        $scope = self::projectScope($user);

        return $scope === null || in_array($project->id, $scope, true);
    }
}
