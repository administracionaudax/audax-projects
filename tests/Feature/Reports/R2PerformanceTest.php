<?php

use App\Domain\Reports\ReportCache;
use App\Enums\Role;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| Rendimiento de las páginas de R2 con el DemoDataSeeder (12 meses, ~9.000 entradas), como
| Phase1PagesPerformanceTest y D-046:
|  - consultas de cada página con la caché de informes FRÍA (se invalida antes de medir) dentro de
|    su presupuesto, sin la misma SQL repetida por fila,
|  - el número de consultas no crece al añadir proyectos, bolsas, tareas, hitos y horas (sin N+1),
|  - la página responde en menos de 1 s en frío (SPEC §17: dashboards en menos de 1 s); las
|    descargas (XLSX, CSV y PDF), que no son dashboards y van en streaming, en menos de 2,5 s.
| Cada bloque del dashboard con importes (KPIs, comparación y cada desglose) valora sus horas con
| RevenueCalculator, que lee proyectos, clientes, bolsas y personas con un número fijo de
| consultas: por eso la misma consulta puede salir hasta 5 veces (una por bloque, no por fila).
| PERF_REPORT=1 imprime consultas y tiempos.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->pages = function (): array {
        $client = Client::query()->where('name', 'Bodegas Arrieta')->sole();
        $project = Project::query()->where('code', 'ARR-WEB')->sole();
        $bank = HourBank::query()->where('project_id', $project->id)->orderBy('id')->firstOrFail();
        $year = 'periodo=anio&fecha='.now()->startOfYear()->toDateString();

        return [
            'reports.client' => "/informes/clientes/{$client->id}",
            'reports.client.year' => "/informes/clientes/{$client->id}?{$year}&comparar=1",
            'reports.client.export' => "/informes/clientes/{$client->id}?formato=xlsx&tabla=bolsas",
            'reports.project' => "/informes/proyectos/{$project->id}",
            'reports.project.year' => "/informes/proyectos/{$project->id}?{$year}&comparar=1",
            // Sin ?tabla=, el libro completo (D-240); y el PDF interno y la versión para el cliente (D-241).
            'reports.project.export' => "/informes/proyectos/{$project->id}?formato=xlsx",
            'reports.project.pdf' => "/informes/proyectos/{$project->id}?{$year}&formato=pdf",
            'reports.project.client.pdf' => "/informes/proyectos/{$project->id}?{$year}&formato=pdf&version=cliente",
            'reports.project.client.export' => "/informes/proyectos/{$project->id}?{$year}&formato=xlsx&version=cliente",
            'reports.billing' => "/facturacion/horas-para-facturar?cliente[]={$client->id}",
            'reports.billing.empty' => '/facturacion/horas-para-facturar',
            'reports.billing.export' => "/facturacion/horas-para-facturar?cliente[]={$client->id}&{$year}&formato=csv",
            'reports.hour-bank-pdf' => "/proyectos/{$project->id}/bolsas/{$bank->id}/pdf",
        ];
    };

    // Presupuesto: lo medido en frío (el máximo de los roles) más un margen de 3.
    $this->budgets = [
        'reports.client' => 39,
        'reports.client.year' => 48,
        'reports.client.export' => 37,
        'reports.project' => 43,
        'reports.project.year' => 52,
        // El libro completo y el PDF interno (D-240): la página más la matriz, los meses, las bolsas,
        // las entradas (por bloques) y los costes.
        'reports.project.export' => 56,
        'reports.project.pdf' => 57,
        // La versión para el cliente (D-241): sin importes ni caché, con las cifras del portal.
        'reports.project.client.pdf' => 25,
        'reports.project.client.export' => 25,
        'reports.billing' => 17,
        'reports.billing.empty' => 6,
        // Cuenta las entradas (límite de filas) y, en frío, calcula el resumen para que el total
        // del fichero sea el de la página (con la página ya vista, el resumen sale de la caché).
        'reports.billing.export' => 22,
        'reports.hour-bank-pdf' => 14,
    ];

    // Mide la segunda petición (la primera calienta ajustes y permisos) con la caché de informes fría.
    // El reloj se congela durante cada pareja de peticiones: en una máquina lenta, una caché con
    // caducidad (la de la weekly, 5 min) podría vencer entre la que calienta y la que se mide.
    $this->measure = function (User $user, string $url): array {
        $this->freezeTime();
        $this->actingAs($user)->get($url);
        ReportCache::bump();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $start = hrtime(true);
        $response = $this->actingAs($user)->get($url);
        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }
        $ms = (hrtime(true) - $start) / 1e6;
        app('events')->forget(QueryExecuted::class);
        $this->travelBack();

        $counts = array_count_values($queries);
        arsort($counts);

        return [
            'status' => $response->getStatusCode(),
            'total' => count($queries),
            'repeats' => $counts === [] ? 0 : (int) reset($counts),
            'repeated' => Str::limit((string) array_key_first($counts), 200),
            'ms' => $ms,
            'shapes' => array_count_values(array_map(fn (string $sql): string => (string) preg_replace('/in \([\d?, ]+\)/', 'in (…)', $sql), $queries)),
        ];
    };

    $this->measureAll = function (User $user): array {
        $results = [];
        foreach (($this->pages)() as $label => $url) {
            $user->refresh();
            $results[$label] = ($this->measure)($user, $url);
        }

        return $results;
    };

    $this->report = function (string $title, array $results): void {
        if (! getenv('PERF_REPORT')) {
            return;
        }
        $lines = ["\n=== {$title}"];
        foreach ($results as $label => $result) {
            $lines[] = sprintf('%-28s %3d %4d q (máx. %d rep.) %7.1f ms', $label, $result['status'], $result['total'], $result['repeats'], $result['ms']);
        }
        fwrite(STDERR, implode("\n", $lines)."\n");
    };

    // Más datos alrededor de lo medido: proyectos del cliente con bolsas, tareas, subtareas, hitos y horas.
    $this->grow = function (int $round): void {
        $client = Client::query()->where('name', 'Bodegas Arrieta')->sole();
        $project = Project::query()->where('code', 'ARR-WEB')->sole();
        $bank = HourBank::query()->where('project_id', $project->id)->orderBy('id')->firstOrFail();
        $raul = User::query()->where('email', 'responsable@example.com')->sole();
        $elena = User::query()->where('email', 'empleado@example.com')->sole();
        $people = User::factory()->count(3)->withRole(Role::Employee)->create(['department_id' => $elena->department_id]);

        foreach (range(1, 3) as $i) {
            $newProject = Project::factory()->hourBank()->create(['client_id' => $client->id, 'owner_user_id' => $raul->id, 'code' => "R2-{$round}-{$i}"]);
            $newBank = HourBank::factory()->hours(20)->create(['project_id' => $newProject->id, 'price_amount' => '900.00']);
            $renewal = HourBank::factory()->hours(20)->create(['project_id' => $newProject->id, 'renewed_from_id' => $newBank->id]);
            foreach ([$newBank, $renewal] as $b) {
                $root = Task::factory()->inBank($b)->create(['estimated_minutes' => 120]);
                $sub = Task::factory()->subtaskOf($root)->create(['estimated_minutes' => 60]);
                foreach ($people as $person) {
                    TimeEntry::factory()->forTask($sub)->on(now()->subDays($i)->toDateString())->minutes(45)->status(TimeEntryStatus::Approved)->create(['user_id' => $person->id, 'description' => 'Horas']);
                }
            }
        }

        foreach ($people as $person) {
            $task = Task::factory()->inBank($bank)->create(['estimated_minutes' => 90]);
            Task::factory()->subtaskOf($task)->create(['estimated_minutes' => 30]);
            Task::factory()->milestone()->create(['project_id' => $project->id, 'due_date' => now()->addDays($round)->toDateString()]);
            TimeEntry::factory()->forTask($task)->on(now()->toDateString())->minutes(30)->status(TimeEntryStatus::Locked)->create(['user_id' => $person->id]);
        }
    };
});

dataset('r2 roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

test('cada página de R2 cabe en su presupuesto de consultas en frío, sin consultas repetidas por fila, y en menos de 1 s', function (string $email) {
    $user = User::query()->where('email', $email)->sole();
    $results = ($this->measureAll)($user);
    ($this->report)($email, $results);

    $employee = $email === 'empleado@example.com';
    $problems = [];
    foreach ($results as $label => $result) {
        $billing = str_starts_with($label, 'reports.billing');
        // La versión para el cliente, solo quien ve todas las horas del proyecto (D-242).
        $client = str_starts_with($label, 'reports.project.client') && ! $user->isAdmin()
            && ! $user->isManagerOf(Project::query()->where('code', 'ARR-WEB')->sole());
        $expected = $employee || $client || ($billing && $email !== 'admin@example.com') ? 403 : 200;

        if ($result['status'] !== $expected) {
            $problems[] = "{$label}: estado {$result['status']} (se esperaba {$expected})";
        }
        if ($result['total'] > $this->budgets[$label]) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto {$this->budgets[$label]})";
        }
        if ($result['repeats'] > 5) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
        $download = str_ends_with($label, '.export') || str_ends_with($label, '.pdf') || $label === 'reports.hour-bank-pdf';
        $maxMs = perfTimeLimit($download ? 2500 : 1000);
        if ($maxMs !== null && $result['ms'] > $maxMs) {
            $problems[] = "{$label}: ".round($result['ms'])." ms en frío (máximo {$maxMs})";
        }
    }

    expect($problems)->toBe([]);
})->with('r2 roles');

test('el número de consultas de las páginas de R2 no crece con los datos (sin N+1)', function (string $email) {
    $user = User::query()->where('email', $email)->sole();

    ($this->grow)(1);
    $before = ($this->measureAll)($user);
    ($this->grow)(2);
    $after = ($this->measureAll)($user);
    ($this->report)("{$email} (antes de crecer)", $before);
    ($this->report)("{$email} (después de crecer)", $after);

    $grew = [];
    foreach ($before as $label => $result) {
        $now = $after[$label];
        if ($now['status'] !== $result['status'] || $now['total'] > $result['total']) {
            $more = [];
            foreach ($now['shapes'] as $shape => $count) {
                if ($count > ($result['shapes'][$shape] ?? 0)) {
                    $more[] = ($result['shapes'][$shape] ?? 0)." → {$count}: ".Str::limit($shape, 200);
                }
            }
            $grew[] = "{$label}: {$result['total']} → {$now['total']} consultas; crecen: ".implode(' | ', $more);
        }
    }

    expect($grew)->toBe([]);
})->with(['admin' => 'admin@example.com', 'responsable' => 'responsable@example.com']);
