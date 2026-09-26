<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles y permisos globales (SPEC §5). Idempotente: se puede ejecutar tantas veces como haga falta.
 * No quita permisos que un admin haya concedido a mano; solo añade los que faltan por defecto.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        foreach (Role::cases() as $role) {
            $model = RoleModel::findOrCreate($role->value, 'web');

            $missing = array_filter(
                $role->defaultPermissions(),
                fn (Permission $permission): bool => ! $model->hasPermissionTo($permission->value),
            );

            if ($missing !== []) {
                $model->givePermissionTo(array_map(fn (Permission $p): string => $p->value, $missing));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
