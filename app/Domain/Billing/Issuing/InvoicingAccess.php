<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Billing\BillingAccess;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Models\User;

/**
 * Quién usa la emisión propia (PLAN-EMISION §3.2; P-5, D-417 y D-418):
 * - Todo va detrás de los módulos `billing` e `invoicing` (apagados; en modo de prueba, solo los
 *   admins, D-239) y de las exclusiones de «Quién ve Facturación» (D-245): sin ellos, nada de emitir
 *   es visible.
 * - **Preparar** (`use-invoicing`): ver las facturas propias, crear, editar, duplicar y borrar
 *   borradores, ver el PDF y cambiar lo no fiscal (nota interna, proyecto y bolsa). Quien tiene
 *   view-billing.
 * - **Emitir, anular y rectificar** (`manage-billing`): además, el permiso `manage-billing` (los
 *   admins por defecto; se puede dar a alguien de finanzas). Los gestores de proyecto no emiten.
 * - **Anular el registro** de una factura que no debió existir (V-03; `void-invoices`): solo un admin
 *   con manage-billing.
 */
final class InvoicingAccess
{
    public static function uses(?User $user): bool
    {
        return BillingAccess::viewsBilling($user) && AppModules::visibleTo($user, AppModule::Invoicing);
    }

    public static function manages(?User $user): bool
    {
        return self::uses($user) && $user !== null && $user->checkPermissionTo(Permission::ManageBilling->value);
    }

    public static function voids(?User $user): bool
    {
        return self::manages($user) && $user !== null && $user->isAdmin();
    }
}
