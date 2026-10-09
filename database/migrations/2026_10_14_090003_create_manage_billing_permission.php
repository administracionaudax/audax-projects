<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso `manage-billing` (PLAN-EMISION §3.2, P-5; D-418): emitir, anular y rectificar facturas
 * propias. Lo tienen los admins (siempre que vean Facturación, D-245); se puede dar a alguien de
 * finanzas sin hacerlo admin. En las instalaciones existentes no se vuelve a ejecutar
 * RolesAndPermissionsSeeder, así que se crea y se asigna aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        PermissionModel::findOrCreate(Permission::ManageBilling->value, 'web');

        $admin = RoleModel::findOrCreate(Role::Admin->value, 'web');

        if (! $admin->hasPermissionTo(Permission::ManageBilling->value)) {
            $admin->givePermissionTo(Permission::ManageBilling->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        PermissionModel::query()
            ->where('name', Permission::ManageBilling->value)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
