<?php

use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento de la vista Carga y de «Mi carga» (Inicio) con los datos de ejemplo (DemoDataSeeder:
| 10 personas, 15 proyectos, ~9.000 entradas de horas) para admin, un responsable (Raúl) y una
| empleada (Elena):
|--------------------------------------------------------------------------
|   1. el reparto se calcula en una sola pasada: un número fijo de consultas por página, sin la
|      misma SQL repetida por fila, y que NO crece al añadir personas, tareas, horas, festivos y
|      ausencias,
|   2. el horizonte de 4 semanas responde en menos de 1 s en local (SPEC §3 y §15).
| WORKLOAD_PERF_REPORT=1 imprime las consultas y los tiempos.
*/

// +1 fija con la Fase 6: el total sin leer del chat (prop compartida `chat.unread`), no por fila.
const WORKLOAD_PERF_BUDGET = 31;

const WORKLOAD_PERF_MAX_REPEATS = 3;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->elena = User::query()->where('email', 'empleado@example.com')->sole();

    // Una celda con carga: la de Elena en el día con más minutos de las próximas 4 semanas.
    $this->pages = fn (): array => [
        'carga' => ['/carga', []],
        'carga.4-semanas' => ['/carga?horizonte=4-semanas', []],
        'carga.3-meses' => ['/carga?horizonte=3-meses', []],
        'carga.filtros' => ['/carga?horizonte=4-semanas&proyecto[]='.Project::query()->where('code', 'ARR-WEB')->value('id'), []],
        'carga.celda' => ['/carga?horizonte=4-semanas&celda='.$this->elena->id.':'.LocalTime::todayString(), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'workload/index',
            'X-Inertia-Partial-Data' => 'cell',
        ]],
        // «Mi carga» de Inicio: la prop diferida `workload`, que se pide después de pintar la página.
        'inicio.mi-carga' => ['/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'home',
            'X-Inertia-Partial-Data' => 'workload',
        ]],
    ];

    /** @return array{status: int, total: int, repeats: int, repeated: string, ms: float, shapes: array<string, int>, props: list<string>|null} */
    $this->measure = function (User $user, string $url, array $headers): array {
        // La primera petición calienta las cachés (ajustes, permisos, estados), como en producción.
        $this->actingAs($user)->get($url, $headers);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $start = hrtime(true);
        $response = $this->actingAs($user)->get($url, $headers);
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
            'shapes' => array_count_values(array_map(fn (string $sql): string => (string) preg_replace('/in \\([\\d?, ]+\\)/', 'in (…)', $sql), $queries)),
            // Las recargas parciales: qué props trae la respuesta (que la medida sea la de verdad).
            'props' => $response->headers->has('X-Inertia') ? array_keys((array) $response->json('props')) : null,
        ];
    };

    $this->measureAll = function (User $user): array {
        $results = [];

        foreach (($this->pages)() as $label => [$url, $headers]) {
            $user->refresh();
            $results[$label] = ($this->measure)($user, $url, $headers);
        }

        if (getenv('WORKLOAD_PERF_REPORT')) {
            $lines = ["\n=== {$user->email}"];
            foreach ($results as $label => $result) {
                $lines[] = sprintf('%-20s %3d %4d q (máx. %d rep.) %7.1f ms', $label, $result['status'], $result['total'], $result['repeats'], $result['ms']);
            }
            fwrite(STDERR, implode("\n", $lines)."\n");
        }

        return $results;
    };

    // Más de todo: personas en Diseño con tareas planificadas, sin planificar y sin asignar,
    // horas imputadas, festivos y ausencias.
    $this->grow = function (int $round): void {
        $design = Department::query()->where('name', 'Diseño')->sole();
        $project = Project::query()->where('code', 'ARR-WEB')->sole();
        $bank = $project->hourBanks()->orderBy('id')->firstOrFail();
        $today = LocalTime::today();

        foreach (range(1, 4) as $i) {
            $person = User::factory()->withRole(Role::Employee)->create(['department_id' => $design->id]);
            $project->addMember($person);
            Absence::factory()->approved()->between($today->addDays(2 + $i)->toDateString(), $today->addDays(3 + $i)->toDateString())->create(['user_id' => $person->id]);

            foreach (range(0, 3) as $j) {
                $task = Task::factory()->inBank($bank)->assignedTo($person)->create([
                    'estimated_minutes' => 600,
                    'start_date' => $today->toDateString(),
                    'due_date' => $today->addDays(5 + $j * 4)->toDateString(),
                ]);
                TimeEntry::factory()->forTask($task)->on($today->subDay()->toDateString())->minutes(30)->create(['user_id' => $person->id]);
                Task::factory()->subtaskOf($task)->assignedTo($this->elena)->create(['estimated_minutes' => 120, 'due_date' => $today->addDays($j + 1)->toDateString()]);
            }

            Task::factory()->inBank($bank)->assignedTo($person)->create(['estimated_minutes' => null, 'due_date' => $today->addDays(3)->toDateString()]);
            Task::factory()->inBank($bank)->create(['assignee_user_id' => null, 'estimated_minutes' => 240, 'due_date' => $today->addDays($i)->toDateString()]);
            Task::factory()->assignedTo($this->elena)->create(['project_id' => Project::factory()->create()->id, 'estimated_minutes' => 60, 'due_date' => $today->addDays($i)->toDateString()]);
        }

        Holiday::factory()->create(['date' => $today->addDays(10 + $round)->toDateString(), 'name' => "Festivo {$round}"]);
    };
});

dataset('workload roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

// Un solo test por rol (los datos de ejemplo se siembran una vez por test): presupuesto de
// consultas con los datos de ejemplo, tiempo del horizonte de 4 semanas con más datos y que las
// consultas no crezcan al añadir todavía más.
test('la vista Carga y «Mi carga» caben en su presupuesto, responden en menos de 1 s y no crecen con los datos', function (string $email) {
    $user = User::query()->where('email', $email)->sole();
    $problems = [];

    // 1. Presupuesto con los datos de ejemplo, sin la misma SQL repetida por fila.
    foreach (($this->measureAll)($user) as $label => $result) {
        if ($result['status'] !== 200) {
            $problems[] = "{$label}: estado {$result['status']}";
        }

        if ($result['total'] > WORKLOAD_PERF_BUDGET) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto ".WORKLOAD_PERF_BUDGET.')';
        }

        if ($result['props'] !== null && array_intersect(['cell', 'workload'], $result['props']) === []) {
            $problems[] = "{$label}: la recarga parcial no trae la prop pedida";
        }

        if ($result['repeats'] > WORKLOAD_PERF_MAX_REPEATS) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
    }

    // 2. Con más personas, tareas, horas, festivos y ausencias, el horizonte de 4 semanas responde
    //    en menos de 1 s en local (SPEC §15).
    ($this->grow)(1);
    $before = ($this->measureAll)($user);

    if ($before['carga.4-semanas']['ms'] >= 1000) {
        $problems[] = sprintf('carga.4-semanas: %.0f ms (máximo 1000 ms)', $before['carga.4-semanas']['ms']);
    }

    // 3. Con todavía más, el número de consultas no crece (el reparto va en una sola pasada).
    ($this->grow)(2);
    $after = ($this->measureAll)($user);

    foreach ($before as $label => $result) {
        $now = $after[$label];

        if ($now['total'] > $result['total']) {
            $more = [];
            foreach ($now['shapes'] as $shape => $count) {
                if ($count > ($result['shapes'][$shape] ?? 0)) {
                    $more[] = ($result['shapes'][$shape] ?? 0)." → {$count}: ".Str::limit($shape, 200);
                }
            }

            $problems[] = "{$label}: {$result['total']} → {$now['total']} consultas; crecen: ".implode(' | ', $more);
        }
    }

    expect($problems)->toBe([]);
})->with('workload roles');
