<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de DESARROLLO: un usuario por rol con contraseña de ejemplo ("password").
 * Nunca en producción: el primer admin real se crea con `php artisan app:install`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder crea usuarios con contraseñas de ejemplo: no se ejecuta en producción. Usa `php artisan app:install`.');
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            DefaultSettingsSeeder::class,
            DepartmentsSeeder::class,
        ]);

        $design = Department::query()->where('name', 'Diseño')->firstOrFail();

        $users = [
            ['role' => Role::Admin, 'name' => 'Ana Administración', 'email' => 'admin@example.com', 'department_id' => null],
            ['role' => Role::DepartmentManager, 'name' => 'Raúl Responsable', 'email' => 'responsable@example.com', 'department_id' => $design->id],
            ['role' => Role::Employee, 'name' => 'Elena Empleada', 'email' => 'empleado@example.com', 'department_id' => $design->id],
            ['role' => Role::Client, 'name' => 'Carlos Cliente', 'email' => 'cliente@example.com', 'department_id' => null],
        ];

        foreach ($users as $data) {
            $user = User::query()->firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'password',
                    'department_id' => $data['department_id'],
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$data['role']->value]);

            if ($data['role'] === Role::DepartmentManager) {
                $design->update(['manager_user_id' => $user->id]);
            }
        }
    }
}
