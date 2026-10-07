<?php

use App\Enums\Role;
use App\Models\Setting;
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
 * Envuelve una escritura que debe fallar (una restricción de la base) en un punto de guardado:
 * `expect(inSavepoint(fn () => …))->toThrow(QueryException::class)`.
 *
 * Los tests corren dentro de la transacción de RefreshDatabase. En PostgreSQL, un error deja esa
 * transacción abortada y cualquier consulta posterior del test falla con «SQLSTATE[25P02] current
 * transaction is aborted» (en SQLite no pasa, por eso en local no se nota). DB::transaction()
 * anidada abre un SAVEPOINT y, al fallar, vuelve a él y relanza la excepción: la base sigue usable
 * igual en los dos motores.
 */
function inSavepoint(Closure $write): Closure
{
    return fn () => DB::transaction($write);
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
 * dispare. Con prioridad baja (nice ≥ 10, como en el servidor con scripts/heavy.sh: dos núcleos y
 * prioridad mínima para no molestar a las otras webs) tampoco se exige: ahí los milisegundos
 * dependen de la carga ajena. Las consultas y el N+1 se comprueban siempre (D-046).
 */
/**
 * ¿Corre con prioridad baja (nice ≥ 10)? Linux: campo 19 de /proc/self/stat; en otros sistemas, no.
 */
function lowPriorityProcess(): bool
{
    $stat = @file_get_contents('/proc/self/stat');
    if ($stat === false) {
        return false;
    }

    // El nombre del proceso va entre paréntesis y puede llevar espacios: se cuenta desde el último «)».
    $fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));

    return isset($fields[16]) && (int) $fields[16] >= 10;
}

function perfTimeLimit(int $localMs): ?int
{
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

    return match (true) {
        getenv('TEST_TOKEN') !== false => null,
        getenv('CI') !== false => max($localMs * 10, 10_000),
        $load !== false && $load[0] > 8 => null,
        lowPriorityProcess() => null,
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

/**
 * Enciende el módulo de la previsión (D-280), que viene apagado por defecto.
 */
function enableForecast(bool $enabled = true): void
{
    Setting::set('modules', ['forecast' => $enabled]);
}

// Registro de jornada (Fase 11): fichar a una hora de Madrid, jornadas enteras y encender el módulo.
require_once __DIR__.'/Support/people.php';

/**
 * Simula a alguien con acceso a la base que quita las protecciones de una tabla del registro de
 * jornada para manipularla (los tests de integridad comprueban que la comprobación lo delata). En
 * PostgreSQL desactiva todos sus triggers de usuario; en SQLite borra el que se indique.
 */
function tamperRegisterTable(string $table, string $sqliteTrigger): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");

        return;
    }

    DB::statement("DROP TRIGGER {$sqliteTrigger}");
}

require_once __DIR__.'/Support/leave.php';
