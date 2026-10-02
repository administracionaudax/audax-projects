<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
| Rendimiento del Gantt (SPEC §3: < 1 s; D-060) con los datos de ejemplo (DemoDataSeeder):
|   1. cada página cabe en su presupuesto de consultas (con la caché caliente),
|   2. ninguna consulta se repite más de 4 veces (síntoma de N+1),
|   3. el número de consultas NO crece al añadir proyectos, tareas, subtareas, dependencias,
|      horas y personas.
| Para admin, un responsable (Raúl) y una empleada (Elena).
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->budgets = [
        'gantt.index' => 19,
        'gantt.index.all' => 19,
        'gantt.project' => 16,
        'gantt.project.reload' => 11,
    ];

    $this->pages = function (): array {
        $project = Project::query()->where('code', 'ARR-WEB')->sole();
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        return [
            'gantt.index' => ['/gantt', []],
            'gantt.index.all' => ['/gantt?estado=todos&escala=mes', []],
            'gantt.project' => ["/proyectos/{$project->id}/gantt", []],
            'gantt.project.reload' => ["/proyectos/{$project->id}/gantt", [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'projects/gantt',
                'X-Inertia-Partial-Data' => 'tasks,dependencies,range',
            ]],
        ];
    };

    $this->measure = function (User $user): array {
        $results = [];

        foreach (($this->pages)() as $label => [$url, $headers]) {
            $user->refresh();
            // Calentamiento (cachés de ajustes, permisos y estados), como en producción.
            $this->actingAs($user)->get($url, $headers);

            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            $response = $this->actingAs($user)->get($url, $headers);
            app('events')->forget(QueryExecuted::class);

            $counts = array_count_values($queries);
            arsort($counts);

            $results[$label] = [
                'status' => $response->getStatusCode(),
                'total' => count($queries),
                'repeats' => $counts === [] ? 0 : (int) reset($counts),
                'repeated' => (string) array_key_first($counts),
            ];
        }

        return $results;
    };

    // Más datos de todo tipo alrededor de las páginas medidas.
    $this->grow = function (int $round): void {
        $project = Project::query()->where('code', 'ARR-WEB')->sole();
        $elena = User::query()->where('email', 'empleado@example.com')->sole();
        $people = User::factory()->count(3)->employee()->create();

        foreach (range(1, 3) as $i) {
            $newProject = Project::factory()->create(['code' => "GANTT-{$round}-{$i}", 'start_date' => now()->toDateString()]);
            $newProject->addMember($elena);
            $people->each(fn (User $user) => $newProject->addMember($user));

            $previous = null;
            foreach ($people as $k => $user) {
                $task = Task::factory()->assignedTo($user)->create([
                    'project_id' => $newProject->id,
                    'start_date' => now()->addDays($k)->toDateString(),
                    'due_date' => now()->addDays($k + 2)->toDateString(),
                    'estimated_minutes' => 120,
                ]);
                Task::factory()->subtaskOf($task)->assignedTo($elena)->create(['due_date' => now()->addDays($k + 1)->toDateString(), 'estimated_minutes' => 30]);
                TimeEntry::factory()->forTask($task)->minutes(30)->create(['user_id' => $user->id]);

                if ($previous !== null) {
                    TaskDependency::query()->create(['predecessor_task_id' => $previous->id, 'successor_task_id' => $task->id]);
                }
                $previous = $task;
            }
        }

        $previous = null;
        foreach ($people as $k => $user) {
            $project->addMember($user);
            $task = Task::factory()->assignedTo($user)->create([
                'project_id' => $project->id,
                'start_date' => now()->addDays($k)->toDateString(),
                'due_date' => now()->addDays($k + 3)->toDateString(),
            ]);
            Task::factory()->subtaskOf($task)->assignedTo($user)->create(['start_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString()]);
            Task::factory()->milestone()->create(['project_id' => $project->id, 'due_date' => now()->addWeeks(2 + $k)->toDateString()]);
            TimeEntry::factory()->forTask($task)->minutes(15)->create(['user_id' => $user->id]);

            if ($previous !== null) {
                TaskDependency::query()->create(['predecessor_task_id' => $previous->id, 'successor_task_id' => $task->id]);
            }
            $previous = $task;
        }
    };
});

dataset('gantt roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

test('cada página del Gantt cabe en su presupuesto de consultas y no crece con los datos (sin N+1)', function (string $email) {
    $user = User::query()->where('email', $email)->sole();
    $problems = [];
    $results = ($this->measure)($user);

    // PERF_REPORT=1 imprime lo medido (orientativo) para ajustar los presupuestos.
    if (getenv('PERF_REPORT')) {
        foreach ($results as $label => $result) {
            fwrite(STDERR, sprintf("\n%-24s %-22s %3d consultas", $email, $label, $result['total']));
        }
    }

    foreach ($results as $label => $result) {
        if ($result['status'] !== 200) {
            $problems[] = "{$label}: estado {$result['status']}";
        }

        if ($result['total'] > $this->budgets[$label]) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto {$this->budgets[$label]})";
        }

        if ($result['repeats'] > 4) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
    }

    expect($problems)->toBe([]);

    // Dos rondas de datos nuevos (la primera crea filas de todas las relaciones): las consultas
    // deben ser las mismas después de la segunda.
    ($this->grow)(1);
    $before = ($this->measure)($user);

    ($this->grow)(2);
    $after = ($this->measure)($user);

    $grew = [];
    foreach ($before as $label => $result) {
        if ($after[$label]['total'] !== $result['total']) {
            $grew[] = "{$label}: {$result['total']} → {$after[$label]['total']}";
        }
    }

    expect($grew)->toBe([]);
})->with('gantt roles');
