<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso `manage-weeklies` (D-147): admins y responsables de departamento. En las instalaciones
 * existentes no se vuelve a ejecutar RolesAndPermissionsSeeder, así que se crea y se asigna aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        PermissionModel::findOrCreate(Permission::ManageWeeklies->value, 'web');

        foreach ([Role::Admin, Role::DepartmentManager] as $role) {
            $model = RoleModel::findOrCreate($role->value, 'web');

            if (! $model->hasPermissionTo(Permission::ManageWeeklies->value)) {
                $model->givePermissionTo(Permission::ManageWeeklies->value);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        PermissionModel::query()
            ->where('name', Permission::ManageWeeklies->value)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
