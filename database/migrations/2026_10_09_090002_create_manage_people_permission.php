<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso `manage-people` (Fase 11, D-330; PLAN-FASE-11 §9 y §14.1 P5: Toni lleva RR. HH.): ve la
 * jornada de toda la plantilla, decide las correcciones de cualquiera y edita los datos laborales.
 * Lo tienen los admins; se puede dar a una persona concreta sin hacerla admin. En las instalaciones
 * existentes no se vuelve a ejecutar RolesAndPermissionsSeeder, así que se crea y se asigna aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        PermissionModel::findOrCreate(Permission::ManagePeople->value, 'web');

        $admin = RoleModel::findOrCreate(Role::Admin->value, 'web');

        if (! $admin->hasPermissionTo(Permission::ManagePeople->value)) {
            $admin->givePermissionTo(Permission::ManagePeople->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        PermissionModel::query()
            ->where('name', Permission::ManagePeople->value)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
