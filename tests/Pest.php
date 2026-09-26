<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/**
 * Inserta una sesión en la tabla sessions (driver "database") y devuelve su id.
 */
function insertSession(?User $user, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0', ?int $lastActivity = null): string
{
    static $counter = 0;
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user?->id,
        'ip_address' => '10.0.'.intdiv(++$counter, 250).'.'.($counter % 250 + 1),
        'user_agent' => $userAgent,
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);

    return $id;
}
