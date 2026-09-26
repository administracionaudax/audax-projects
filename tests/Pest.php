<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest
|--------------------------------------------------------------------------
| Local: SQLite en memoria (.env.testing). Servidor: PostgreSQL real con base *_test.
| Los tests de Feature se envuelven en RefreshDatabase y parten de los roles y permisos
| por defecto. La guarda contra bases que no sean de test está en Tests\TestCase::setUp().
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Utilidades
|--------------------------------------------------------------------------
*/

/**
 * Crea un usuario con el rol indicado ('admin', 'department_manager', 'employee', 'client').
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $role, array $attributes = []): User
{
    return User::factory()->withRole(Role::from($role))->create($attributes);
}
