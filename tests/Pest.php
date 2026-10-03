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

/**
 * Límite de tiempo de una página en los tests de rendimiento (objetivo del SPEC §17: < 1 s).
 * El tiempo depende de la máquina: se exige en una ejecución normal en local; en paralelo
 * (TEST_TOKEN) o con la máquina saturada (carga media > 8) solo se informa (null), y en la CI
 * (PostgreSQL en un contenedor compartido, con picos de lentitud) solo se vigila que no se
 * dispare. Las consultas y el N+1 se comprueban siempre; la medida buena es la del servidor en
 * el despliegue (D-046).
 */
function perfTimeLimit(int $localMs): ?int
{
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

    return match (true) {
        getenv('TEST_TOKEN') !== false => null,
        getenv('CI') !== false => max($localMs * 10, 10_000),
        $load !== false && $load[0] > 8 => null,
        default => $localMs,
    };
}

/**
 * Texto visible del HTML de un informe (PDF con el motor html o versión para imprimir, Fase 9):
 * sin CSS, scripts ni el logotipo, con las entidades decodificadas y los espacios normalizados.
 */
function reportHtmlText(string $html): string
{
    $html = (string) preg_replace('#<(style|script|svg)\b.*?</\1>#s', ' ', $html);
    $text = html_entity_decode(strip_tags((string) preg_replace('#<(td|th|dt|dd|p|h\d|li|span|div)\b#', ' $0', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}
