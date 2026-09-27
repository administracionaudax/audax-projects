<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento del calendario de tareas, las dependencias del panel y los próximos hitos (D-061,
| D-062) con los datos de ejemplo (DemoDataSeeder: 12 meses, 15 proyectos).
|--------------------------------------------------------------------------
| Como tests/Feature/Performance/Phase1PagesPerformanceTest.php, para admin, un responsable
| (Raúl) y una empleada (Elena):
|   1. cada página cabe en su presupuesto de consultas (medido con la caché caliente) y ninguna
|      consulta se repite más de 4 veces,
|   2. el número de consultas no crece al añadir tareas con y sin fecha, subtareas, personas,
|      dependencias e hitos (dos rondas).
| PERF_REPORT=1 imprime la tabla.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->project = Project::query()->where('code', 'ARR-WEB')->sole();
    $this->panelTask = Task::query()->where('project_id', $this->project->id)->whereNull('parent_task_id')->orderBy('id')->firstOrFail();

    // Presupuesto: lo medido (el máximo de los tres roles) + 3, como en la Fase 1.
    $this->budgets = [
        'home' => 16,
        'projects.show' => 24,
        'calendar.month' => 21,
        'calendar.week' => 21,
        'calendar.filtered' => 21,
        'calendar.partial' => 11,
        'calendar.panel' => 26,
        'dependencies.candidates' => 7,
        'dependencies.candidates.search' => 7,
    ];

    $this->pages = function (): array {
        $p = $this->project->id;
        $headers = fn (string $prop): array => [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'projects/tasks',
            'X-Inertia-Partial-Data' => $prop,
        ];
        $month = now()->format('Y-m');

        return [
            'home' => ['/', []],
            'projects.show' => ["/proyectos/{$p}", []],
            'calendar.month' => ["/proyectos/{$p}/tareas?vista=calendario&mes={$month}", []],
            'calendar.week' => ["/proyectos/{$p}/tareas?vista=calendario&semana=".now()->toDateString(), []],
            'calendar.filtered' => ["/proyectos/{$p}/tareas?vista=calendario&mes={$month}&mias=1&completadas=1", []],
            'calendar.partial' => ["/proyectos/{$p}/tareas?vista=calendario&mes={$month}", $headers('calendar')],
            'calendar.panel' => ["/proyectos/{$p}/tareas?vista=calendario&mes={$month}&tarea={$this->panelTask->id}", $headers('panel')],
            'dependencies.candidates' => ["/tareas/{$this->panelTask->id}/dependencias/candidatas", []],
            'dependencies.candidates.search' => ["/tareas/{$this->panelTask->id}/dependencias/candidatas?buscar=dis", []],
        ];
    };

    $this->measure = function (User $user, string $url, array $headers): array {
        // Calienta las cachés (ajustes, permisos y estados) como en producción y mide la segunda.
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
        ];
    };

    $this->measureAll = function (User $user): array {
        $results = [];

        foreach (($this->pages)() as $label => [$url, $headers]) {
            $user->refresh();
            $results[$label] = ($this->measure)($user, $url, $headers);
        }

        return $results;
    };

    // Más datos alrededor de lo medido: personas, tareas del mes con y sin fecha, subtareas,
    // dependencias del panel y de otras tareas, e hitos del proyecto y de los de la persona.
    $this->grow = function (int $round): void {
        $people = User::factory()->count(3)->employee()->create();
        $elena = User::query()->where('email', 'empleado@example.com')->sole();

        foreach ($people as $index => $person) {
            $this->project->addMember($person);
            $root = Task::factory()->assignedTo($person)->create([
                'project_id' => $this->project->id,
                'hour_bank_id' => $this->panelTask->hour_bank_id,
                'start_date' => now()->startOfMonth()->addDays($index)->toDateString(),
                'due_date' => now()->startOfMonth()->addDays(10 + $index)->toDateString(),
            ]);
            Task::factory()->subtaskOf($root)->assignedTo($elena)->create(['due_date' => now()->startOfMonth()->addDays(5)->toDateString()]);
            Task::factory()->assignedTo($elena)->create(['project_id' => $this->project->id, 'hour_bank_id' => $this->panelTask->hour_bank_id]);
            Task::factory()->milestone()->create([
                'project_id' => $this->project->id,
                'hour_bank_id' => $this->panelTask->hour_bank_id,
                'due_date' => now()->addDays(3 * $index + $round)->toDateString(),
            ]);

            // La tarea del panel bloquea a la nueva y depende de otra nueva (sin ciclos).
            $before = Task::factory()->create(['project_id' => $this->project->id, 'hour_bank_id' => $this->panelTask->hour_bank_id, 'due_date' => now()->toDateString()]);
            TaskDependency::query()->create(['predecessor_task_id' => $this->panelTask->id, 'successor_task_id' => $root->id]);
            TaskDependency::query()->create(['predecessor_task_id' => $before->id, 'successor_task_id' => $this->panelTask->id]);

            // Un proyecto nuevo de Elena con un hito próximo (Inicio).
            $other = Project::factory()->create(['code' => "PLAN-{$round}-{$index}"]);
            $other->addMember($elena);
            Task::factory()->milestone()->create(['project_id' => $other->id, 'due_date' => now()->addDays($index + 1)->toDateString()]);
        }
    };

    $this->report = function (string $title, array $results): void {
        if (! getenv('PERF_REPORT')) {
            return;
        }

        $lines = ["\n=== {$title}"];
        foreach ($results as $label => $result) {
            $lines[] = sprintf('%-32s %3d %4d q (máx. %d rep.) %7.1f ms', $label, $result['status'], $result['total'], $result['repeats'], $result['ms']);
        }

        fwrite(STDERR, implode("\n", $lines)."\n");
    };
});

test('el calendario, las dependencias y los hitos caben en su presupuesto de consultas y no crecen con los datos (sin N+1)', function () {
    // Admin, un responsable (Raúl) y una empleada (Elena), con una sola carga de los datos de ejemplo.
    $users = User::query()->whereIn('email', ['admin@example.com', 'responsable@example.com', 'empleado@example.com'])->get();
    expect($users)->toHaveCount(3);

    // 1. Presupuesto con los datos de ejemplo.
    $problems = [];

    foreach ($users as $user) {
        $results = ($this->measureAll)($user);
        ($this->report)($user->email, $results);
        $canEdit = Gate::forUser($user)->allows('update', $this->panelTask->load('project'));

        foreach ($results as $label => $result) {
            $expected = str_starts_with($label, 'dependencies.') && ! $canEdit ? 403 : 200;

            if ($result['status'] !== $expected) {
                $problems[] = "{$user->email} {$label}: estado {$result['status']} (se esperaba {$expected})";
            }

            if ($result['total'] > $this->budgets[$label]) {
                $problems[] = "{$user->email} {$label}: {$result['total']} consultas (presupuesto {$this->budgets[$label]})";
            }

            if ($result['repeats'] > 4) {
                $problems[] = "{$user->email} {$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
            }
        }
    }

    expect($problems)->toBe([]);

    // 2. Una ronda para que existan filas de todas las relaciones y otra con más: mismas consultas.
    ($this->grow)(1);
    $before = $users->mapWithKeys(fn (User $user): array => [$user->email => ($this->measureAll)($user)])->all();
    ($this->grow)(2);
    $grew = [];

    foreach ($users as $user) {
        $after = ($this->measureAll)($user);
        ($this->report)("{$user->email} (antes de crecer)", $before[$user->email]);
        ($this->report)("{$user->email} (después de crecer)", $after);

        foreach ($before[$user->email] as $label => $result) {
            $now = $after[$label];

            // La actividad del resumen del proyecto traduce ids a nombres (una consulta por tipo
            // citado): se tolera 1 de más, como en la Fase 1.
            if ($now['status'] !== $result['status'] || $now['total'] > $result['total'] + ($label === 'projects.show' ? 1 : 0)) {
                $more = [];
                foreach ($now['shapes'] as $shape => $count) {
                    if ($count > ($result['shapes'][$shape] ?? 0)) {
                        $more[] = ($result['shapes'][$shape] ?? 0)." → {$count}: ".Str::limit($shape, 200);
                    }
                }

                $grew[] = "{$user->email} {$label}: {$result['total']} → {$now['total']} consultas; crecen: ".implode(' | ', $more);
            }
        }
    }

    expect($grew)->toBe([]);
});
