<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rol «Colaborador externo» (Fase 8, D-134). En las instalaciones existentes no se vuelve a
 * ejecutar RolesAndPermissionsSeeder, así que el rol se crea aquí (sin permisos).
 */
return new class extends Migration
{
    public function up(): void
    {
        RoleModel::findOrCreate(Role::Collaborator->value, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        RoleModel::query()->where('name', Role::Collaborator->value)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
