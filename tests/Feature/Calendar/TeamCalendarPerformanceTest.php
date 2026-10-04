<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
| Rendimiento del calendario del equipo (D-144):
|   1. cada vista cabe en su presupuesto de consultas y el número no crece al añadir tareas,
|      personas, proyectos, subtareas y ausencias (sin N+1; preventLazyLoading está activo),
|   2. un mes de todo el equipo con unas 18.000 tareas en la base responde rápido: la consulta
|      está acotada por el rango visible (índices de inicio y entrega).
| «Hoy» es el miércoles 07/10/2026 en Madrid.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));

    $this->department = Department::factory()->create();
    $this->admin = userWithRole('admin');
    $this->employee = userWithRole('employee', ['department_id' => $this->department->id]);

    $this->views = function (): array {
        $headers = fn (string $prop): array => [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'calendar/index',
            'X-Inertia-Partial-Data' => $prop,
        ];
        $task = Task::query()->orderBy('id')->firstOrFail();

        return [
            'week' => ['/calendario', []],
            'month' => ['/calendario?vista=mes', []],
            'day' => ['/calendario?vista=dia', []],
            'people.week' => ['/calendario?personas=1', []],
            'people.day' => ['/calendario?vista=dia&personas=1', []],
            'filtered' => ["/calendario?vista=mes&q=a&hechas=1&departamento={$this->department->id}", []],
            'partial' => ['/calendario?vista=mes&personas=1', $headers('calendar')],
            'partial.people' => ['/calendario?personas=1&fecha=2026-10-08', $headers('calendar')],
            'panel' => ["/calendario?tarea={$task->id}", $headers('panel,panelLookups')],
            'creatable' => ['/calendario', $headers('creatable')],
        ];
    };

    $this->count = function (User $user, string $url, array $headers): int {
        $this->flushSession();
        $this->actingAs($user)->get($url, $headers)->assertOk();

        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $this->actingAs($user)->get($url, $headers)->assertOk();
        app('events')->forget(QueryExecuted::class);

        return $queries;
    };

    $this->measure = function (User $user): array {
        $counts = [];
        foreach (($this->views)() as $label => [$url, $headers]) {
            $user->refresh();
            $counts[$label] = ($this->count)($user, $url, $headers);
        }

        return $counts;
    };

    $this->grow = function (int $round): void {
        foreach (range(1, 3) as $i) {
            $person = User::factory()->employee()->create(['department_id' => $this->department->id]);
            $project = Project::factory()->withMembers([$person, $this->employee])->create(['owner_user_id' => $this->admin->id]);
            $parent = Task::factory()->assignedTo($person)->create([
                'project_id' => $project->id,
                'start_date' => '2026-10-0'.$i,
                'due_date' => '2026-10-1'.$i,
                'estimated_minutes' => 600,
            ]);
            Task::factory()->subtaskOf($parent)->assignedTo($this->employee)->create(['due_date' => '2026-10-0'.(5 + $i), 'estimated_minutes' => 120]);
            Task::factory()->milestone()->create(['project_id' => $project->id, 'due_date' => '2026-10-08']);
            Absence::factory()->approved()->between('2026-10-0'.(5 + $i), '2026-10-0'.(5 + $i))->create(['user_id' => $person->id]);
        }
    };
});

it('cada vista cabe en su presupuesto de consultas y no crece con los datos', function () {
    ($this->grow)(0);

    // Lo medido (el máximo de admin y empleada) + 3: sesión y props compartidas, opciones de los
    // filtros (personas, departamentos, proyectos con su cliente, tipos y estados), las tareas del
    // rango (una consulta) con sus padres y responsables; en «Personas», además, las filas, la
    // capacidad (horarios, festivos y ausencias) y la carga planificada (WorkloadPlanner).
    $budgets = [
        'week' => 18,
        'month' => 18,
        'day' => 18,
        'people.week' => 28,
        'people.day' => 28,
        'filtered' => 18,
        'partial' => 8,
        'partial.people' => 18,
        'panel' => 36,
        'creatable' => 6,
    ];

    foreach ([$this->admin, $this->employee] as $user) {
        $before = ($this->measure)($user);
        ($this->grow)(1);
        ($this->grow)(2);
        $after = ($this->measure)($user);

        expect($after)->toBe($before);

        foreach ($after as $label => $queries) {
            expect($queries)->toBeLessThanOrEqual($budgets[$label], "{$label}: {$queries} consultas (presupuesto {$budgets[$label]})");
        }
    }
});

it('un mes de todo el equipo con 18.000 tareas en la base responde rápido', function () {
    $status = TaskStatus::defaultStatus()->id;
    $people = User::factory()->count(30)->employee()->create(['department_id' => $this->department->id]);
    $projects = Project::factory()->count(20)->create(['owner_user_id' => $this->admin->id]);
    $start = CarbonImmutable::parse('2025-10-01');
    $now = now()->toDateTimeString();

    // 18.000 tareas repartidas en dos años (unas 750 por mes), una de cada cuatro con inicio.
    $rows = [];
    for ($i = 0; $i < 18_000; $i++) {
        $due = $start->addDays($i % 730);
        $rows[] = [
            'project_id' => $projects[$i % 20]->id,
            'title' => "Tarea {$i}",
            'status_id' => $status,
            'priority' => 'normal',
            'assignee_user_id' => $i % 7 === 0 ? null : $people[$i % 30]->id,
            'start_date' => $i % 4 === 0 ? $due->subDays(5)->toDateString() : null,
            'due_date' => $due->toDateString(),
            'is_milestone' => false,
            'is_billable' => true,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($rows) === 1000) {
            DB::table('tasks')->insert($rows);
            $rows = [];
        }
    }

    // Caliente y mide la segunda.
    $this->actingAs($this->admin)->get('/calendario?vista=mes')->assertOk();
    $started = hrtime(true);
    $props = $this->actingAs($this->admin)->get('/calendario?vista=mes')->assertOk()->viewData('page')['props'];
    $ms = (hrtime(true) - $started) / 1e6;

    expect(Task::query()->count())->toBe(18_000)
        // Del lunes 28/09 al domingo 01/11: 5 semanas.
        ->and(count($props['calendar']['tasks']))->toBeGreaterThan(700)
        ->and($props['calendar']['truncated'])->toBeFalse();

    $limit = perfTimeLimit(1500);
    if ($limit !== null) {
        expect($ms)->toBeLessThan($limit);
    }
});
