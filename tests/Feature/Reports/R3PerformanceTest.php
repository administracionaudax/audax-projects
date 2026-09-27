<?php

use App\Domain\Reports\ReportCache;
use App\Http\Controllers\Reports\HoursExportController;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| Rendimiento de R3 con los datos de ejemplo (DemoDataSeeder: 12 meses, ~9.000 entradas), para la
| admin, un responsable (Raúl, Diseño) y una empleada (Elena):
|  - el informe detallado cabe en su presupuesto de consultas con la caché fría (D-046: la primera
|    visita tras escribir horas) y caliente, sin consultas repetidas por fila, y responde en < 1 s,
|  - la exportación de horas de un año hace un número de consultas que depende de los bloques, no
|    de las filas.
| R3_PERF_REPORT=1 php -d memory_limit=1G vendor/bin/pest tests/Feature/Reports/R3PerformanceTest.php
|   imprime las consultas y los tiempos (locales y orientativos).
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    // Mide una petición (ya calentada la sesión y los permisos): consultas, repeticiones y tiempo.
    $this->measure = function (User $user, string $url, bool $cold): array {
        $this->actingAs($user)->get($url);

        if ($cold) {
            ReportCache::bump();
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $start = hrtime(true);
        $response = $this->actingAs($user)->get($url);
        $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
        $ms = (hrtime(true) - $start) / 1e6;

        app('events')->forget(QueryExecuted::class);

        $counts = array_count_values($queries);
        arsort($counts);

        if (getenv('R3_PERF_REPORT')) {
            fwrite(STDERR, sprintf("\n%-28s %-5s %3d %3d q (máx. %d rep.) %7.1f ms %8d bytes", $user->email, $cold ? 'fría' : 'cal.', $response->getStatusCode(), count($queries), $counts === [] ? 0 : reset($counts), $ms, strlen((string) $content)));
        }

        return [
            'status' => $response->getStatusCode(),
            'queries' => count($queries),
            'repeats' => $counts === [] ? 0 : (int) reset($counts),
            'repeated' => Str::limit((string) array_key_first($counts), 160),
            'ms' => $ms,
        ];
    };

    $this->user = fn (string $email): User => User::query()->where('email', $email)->sole();
});

dataset('r3_roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

it('el informe detallado cabe en su presupuesto de consultas y responde en menos de 1 s', function (string $email) {
    $user = ($this->user)($email);
    // Presupuesto con la caché fría: lo medido (máximo de los tres roles) más 3; sin capacidad,
    // estimación ni importes (Metrics::hours), que el detallado no muestra. Con la caché caliente
    // solo quedan las props compartidas y la sesión (2 a 5 consultas).
    $pages = [
        'por defecto (proyecto × semana, mes)' => ['/informes/detalle', 11],
        'persona × proyecto, año' => ['/informes/detalle?periodo=anio&filas=persona&columnas=proyecto', 11],
        'tarea × mes, año (recortada)' => ['/informes/detalle?periodo=anio&filas=tarea&columnas=mes&medida=facturables', 12],
        'cliente × semana, trimestre, comparando' => ['/informes/detalle?periodo=trimestre&filas=cliente&columnas=semana&comparar=1', 12],
    ];

    $problems = [];
    foreach ($pages as $label => [$url, $coldBudget]) {
        foreach (['fría' => true, 'caliente' => false] as $cache => $cold) {
            $result = ($this->measure)($user, $url, $cold);
            $budget = $cold ? $coldBudget : 8;

            // El tiempo, el mejor de hasta tres medidas: un pico de carga de la máquina (tests en
            // paralelo) no es el informe. Las consultas son siempre las mismas.
            for ($retry = 0; $retry < 2 && $result['ms'] > 1000; $retry++) {
                $result['ms'] = min($result['ms'], ($this->measure)($user, $url, $cold)['ms']);
            }

            if ($result['status'] !== 200) {
                $problems[] = "{$label} ({$cache}): estado {$result['status']}";
            }
            if ($result['queries'] > $budget) {
                $problems[] = "{$label} ({$cache}): {$result['queries']} consultas (presupuesto {$budget})";
            }
            if ($result['repeats'] > 3) {
                $problems[] = "{$label} ({$cache}): la misma consulta {$result['repeats']} veces: {$result['repeated']}";
            }
            $limit = perfTimeLimit(1000);
            if ($limit !== null && $result['ms'] > $limit) {
                $problems[] = "{$label} ({$cache}): ".round($result['ms']).' ms';
            }
        }
    }

    expect($problems)->toBe([]);
})->with('r3_roles');

it('la exportación de horas de un año hace las mismas consultas por bloque, sin consultas por fila', function () {
    $admin = ($this->user)('admin@example.com');
    $url = '/informes/horas/exportar?periodo=anio&formato=csv';
    $result = ($this->measure)($admin, $url, false);

    $entries = TimeEntry::query()->whereBetween('date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()])->count();
    $chunks = (int) ceil($entries / HoursExportController::CHUNK);

    // Por bloque: las entradas, sus 6 relaciones y la valoración (RevenueCalculator: 8 consultas,
    // una de ellas la base de los proyectos a precio cerrado con la estimación de sus tareas raíz).
    expect($result['status'])->toBe(200)
        ->and($entries)->toBeGreaterThan(1000)
        ->and($result['queries'])->toBeLessThanOrEqual(3 + $chunks * 15)
        ->and($result['repeats'])->toBeLessThanOrEqual($chunks);
});
