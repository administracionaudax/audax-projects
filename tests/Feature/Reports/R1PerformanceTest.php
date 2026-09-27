<?php

use App\Domain\Reports\ReportCache;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| R1 · Rendimiento de los dashboards con los datos de ejemplo (DemoDataSeeder: 12 meses, ~9.000
| entradas, 15 proyectos, 11 bolsas) para admin, un responsable (Raúl, Diseño) y una empleada
| (Elena, Diseño). Para cada página:
|   1. «en frío» (justo después de escribir un dato: la caché de informes invalidada, D-046) cabe en
|      su presupuesto de consultas, sin la misma SQL repetida por fila (N+1) y en menos de 1 s,
|   2. «en caliente» (la caché ya calculada) apenas consulta la base de datos.
| R1_PERF_REPORT=1 php -d memory_limit=1G vendor/bin/pest tests/Feature/Reports/R1PerformanceTest.php
| imprime la tabla de consultas y tiempos (locales y orientativos).
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $design = Department::query()->where('name', 'Diseño')->sole();
    $elena = User::query()->where('email', 'empleado@example.com')->sole();

    // Página → [URL, presupuesto en frío, presupuesto en caliente]. Lo medido (máximo de los tres
    // roles; el admin es quien más consulta porque valora el ingreso) más un margen de 4 a 6. En
    // caliente solo quedan la sesión, los permisos y las props compartidas. Al integrar la Fase 2,
    // la valoración suma una consulta por cálculo (base de precio cerrado con las tareas raíz, R2).
    $this->pages = [
        'reports.index' => ['/informes', 14, 14],
        'reports.direction' => ['/informes/direccion', 75, 8],
        'reports.direction.year' => ['/informes/direccion?periodo=anio&comparar=1', 84, 8],
        'reports.department' => ["/informes/departamentos/{$design->id}?comparar=1", 61, 8],
        'reports.person' => ["/informes/personas/{$elena->id}?periodo=trimestre", 60, 10],
        'reports.person.filtered' => ["/informes/personas/{$elena->id}?cliente[]=1", 56, 10],
        'reports.direction.export' => ['/informes/direccion?formato=xlsx&tabla=proyectos', 14, 6],
        'home' => ['/', 28, 12],
    ];

    // Cada valoración económica (RevenueCalculator::compute: resumen, cada reparto, la serie y la
    // comparación) carga una vez sus tarifas: la misma consulta se repite una vez por métrica,
    // nunca por fila. Más repeticiones que estas delatan un N+1.
    $this->maxRepeats = 6;

    // Objetivo del SPEC §17: < 1 s. El tiempo depende de la máquina: se exige en una ejecución
    // normal en local; en la CI (PostgreSQL en un contenedor compartido) solo se vigila que no se
    // dispare, y en paralelo (pest --parallel, con TEST_TOKEN) o con la máquina saturada (carga
    // media > 8) solo se informa, porque los procesos compiten por la CPU. La medida buena es la
    // del servidor en el despliegue (D-046).
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $this->maxMs = match (true) {
        getenv('TEST_TOKEN') !== false => null,
        getenv('CI') !== false => 3000,
        $load !== false && $load[0] > 8 => null,
        default => 1000,
    };

    $this->expected = fn (string $email, string $page): int => match (true) {
        $email === 'empleado@example.com' && in_array($page, ['reports.direction', 'reports.direction.year', 'reports.department', 'reports.direction.export'], true) => 403,
        default => 200,
    };

    $this->measure = function (User $user, string $url): array {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $start = hrtime(true);
        $response = $this->actingAs($user)->get($url);
        if (method_exists($response->baseResponse, 'sendContent') && str_contains((string) $response->headers->get('Content-Type'), 'spreadsheet')) {
            $response->streamedContent();
        }
        $ms = (hrtime(true) - $start) / 1e6;

        app('events')->forget(QueryExecuted::class);

        $counts = array_count_values($queries);
        arsort($counts);

        return [
            'status' => $response->getStatusCode(),
            'total' => count($queries),
            'repeats' => $counts === [] ? 0 : (int) reset($counts),
            'repeated' => Str::limit((string) array_key_first($counts), 200),
            'ms' => $ms,
        ];
    };
});

dataset('r1_roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

test('cada dashboard de R1 cabe en su presupuesto de consultas, sin N+1 y en menos de 1 s', function (string $email) {
    $user = User::query()->where('email', $email)->sole();
    $problems = [];
    $report = [];

    foreach ($this->pages as $label => [$url, $coldBudget, $warmBudget]) {
        // Calienta las cachés de ajustes, permisos y estados (como en producción) y después invalida
        // la de informes: la medida «en frío» es la primera visita tras escribir un dato.
        $this->actingAs($user)->get($url);
        ReportCache::bump();
        $user->refresh();

        $cold = ($this->measure)($user, $url);
        $warm = ($this->measure)($user, $url);

        // Un pico de carga de la máquina no es un problema de la página: si se pasa de tiempo, se
        // vuelve a medir en frío una vez y cuenta la mejor de las dos.
        if ($this->maxMs !== null && $cold['ms'] > $this->maxMs) {
            ReportCache::bump();
            $cold['ms'] = min($cold['ms'], ($this->measure)($user, $url)['ms']);
        }
        $report[] = sprintf('%-28s %3d  frío %3d q (máx. %d rep.) %7.1f ms · caliente %3d q %6.1f ms', $label, $cold['status'], $cold['total'], $cold['repeats'], $cold['ms'], $warm['total'], $warm['ms']);

        $expected = ($this->expected)($email, $label);
        if ($cold['status'] !== $expected || $warm['status'] !== $expected) {
            $problems[] = "{$label}: estado {$cold['status']}/{$warm['status']} (se esperaba {$expected})";
        }
        if ($expected !== 200) {
            continue;
        }
        if ($cold['total'] > $coldBudget) {
            $problems[] = "{$label}: {$cold['total']} consultas en frío (presupuesto {$coldBudget})";
        }
        if ($warm['total'] > $warmBudget) {
            $problems[] = "{$label}: {$warm['total']} consultas en caliente (presupuesto {$warmBudget})";
        }
        if ($cold['repeats'] > $this->maxRepeats) {
            $problems[] = "{$label}: la misma consulta {$cold['repeats']} veces: {$cold['repeated']}";
        }
        if ($this->maxMs !== null && $cold['ms'] > $this->maxMs) {
            $problems[] = sprintf('%s: %.0f ms en frío (límite %d ms; objetivo < 1 s, SPEC §17)', $label, $cold['ms'], $this->maxMs);
        }
    }

    if (getenv('R1_PERF_REPORT')) {
        fwrite(STDERR, "\n=== {$email}\n".implode("\n", $report)."\n");
    }

    expect($problems)->toBe([]);
})->with('r1_roles');
