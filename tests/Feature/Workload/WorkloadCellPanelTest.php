<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Workload\Concerns\BuildsWorkloadScenario;

/*
| Panel de una celda de la vista Carga (?celda=persona:fecha, D-052): las tareas que forman esa
| carga, qué se puede hacer con cada una y quién puede abrirlo.
*/

pest()->use(BuildsWorkloadScenario::class);

beforeEach(function () {
    $this->buildWorkloadScenario();

    $this->cell = function (string $who, string $person, string $date, string $query = ''): ?array {
        $cell = [];
        $url = "/carga?celda={$this->people[$person]->id}:{$date}".($query === '' ? '' : '&'.$query);

        $this->actingAs($this->people[$who])
            ->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$cell) {
                $cell = $page->toArray()['props']['cell'];
            });

        return $cell;
    };
});

it('lista las tareas que forman la carga de la celda con sus minutos de ese día, proyecto, bolsa y restante', function () {
    $cell = ($this->cell)('raul', 'elena', '2026-10-13');

    expect($cell)->toMatchArray([
        'key' => "{$this->people['elena']->id}:2026-10-13",
        'person' => ['id' => $this->people['elena']->id, 'name' => 'Elena Empleada', 'department' => 'Diseño'],
        'from' => '2026-10-13',
        'to' => '2026-10-13',
        'planned' => 600,
        'capacity' => 480,
    ])
        ->and($cell['days'])->toHaveCount(1)
        ->and($cell['days'][0])->toMatchArray(['date' => '2026-10-13', 'planned' => 600, 'capacity' => 480, 'base' => 480])
        ->and(array_column($cell['tasks'], 'title'))->toEqualCanonicalizing(['Maquetar la home', 'Pantalla de reservas'])
        ->and(array_column($cell['tasks'], 'minutes'))->toBe([300, 300]);

    $web = collect($cell['tasks'])->firstWhere('title', 'Maquetar la home');

    expect($web)->toMatchArray([
        'id' => $this->tasks['elena_web']->id,
        'project' => ['id' => $this->projects['web']->id, 'code' => 'WEB', 'name' => 'Web corporativa', 'color' => $this->projects['web']->color],
        'client' => 'Acme',
        'hour_bank' => null,
        'assignee_id' => $this->people['elena']->id,
        'estimated_minutes' => 1200,
        'logged_minutes' => 0,
        'remaining_minutes' => 1200,
        'start_date' => '2026-10-13',
        'due_date' => '2026-10-16',
        'overdue' => false,
        'can_edit' => true,
    ])->and($web['assignee_ids'])->toEqualCanonicalizing([$this->people['elena']->id, $this->people['lucia']->id, $this->people['raul']->id]);
});

it('las tareas vencidas salen en la celda de hoy, marcadas', function () {
    $cell = ($this->cell)('elena', 'elena', '2026-10-06', 'horizonte=semana-actual');

    expect($cell['overdue'])->toBeTrue()
        ->and($cell['tasks'])->toHaveCount(1)
        ->and($cell['tasks'][0])->toMatchArray(['title' => 'Revisión atrasada', 'minutes' => 300, 'overdue' => true, 'due_date' => '2026-10-02']);
});

it('una celda semanal (3 meses) junta los días de la semana, con el detalle de cada uno', function () {
    $cell = ($this->cell)('ana', 'lucia', '2026-10-14', 'horizonte=3-meses');

    expect($cell)->toMatchArray(['key' => "{$this->people['lucia']->id}:2026-10-12", 'from' => '2026-10-12', 'to' => '2026-10-18', 'planned' => 480, 'capacity' => 960])
        ->and(array_column($cell['days'], 'date'))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'])
        ->and($cell['days'][0]['reason'])->toBe(['type' => 'holiday', 'label' => 'Fiesta Nacional de España'])
        ->and($cell['days'][1]['reason'])->toBe(['type' => 'absence', 'label' => 'Vacaciones'])
        ->and($cell['days'][5]['reason'])->toBe(['type' => 'off', 'label' => null])
        ->and($cell['tasks'][0])->toMatchArray(['title' => 'Iconos', 'minutes' => 480]);
});

it('la primera semana del horizonte de 3 meses empieza hoy', function () {
    $cell = ($this->cell)('ana', 'elena', '2026-10-05', 'horizonte=3-meses');

    expect($cell)->toBeNull();

    $cell = ($this->cell)('ana', 'elena', '2026-10-08', 'horizonte=3-meses');

    expect($cell)->toMatchArray(['key' => "{$this->people['elena']->id}:2026-10-06", 'from' => '2026-10-06', 'to' => '2026-10-11', 'planned' => 300]);
});

it('una fecha fuera del horizonte no abre nada', function () {
    expect(($this->cell)('ana', 'elena', '2026-11-30'))->toBeNull();
});

it('en una visita completa, la celda de una persona fuera de su alcance se ignora: la página se abre sin panel (D-052)', function (string $who, string $person, string $date) {
    // Como el resto de valores de la URL que no valen (WorkloadFilters): un enlace compartido
    // con la celda de otra persona abre la vista, no un error.
    expect(($this->cell)($who, $person, $date))->toBeNull();
})->with([
    'una empleada, la de una compañera' => ['elena', 'lucia', '2026-10-15'],
    'un responsable, la de otro departamento' => ['raul', 'pablo', '2026-10-13'],
    'un gestor, la de alguien de otro departamento' => ['sergio', 'lucia', '2026-10-15'],
    'un admin, la de una persona desactivada' => ['ana', 'olga', '2026-10-15'],
]);

it('la recarga parcial del panel (only=cell) de una persona fuera de su alcance responde 403 (D-052)', function (string $who, string $person, string $date) {
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->people[$who])
        ->get("/carga?celda={$this->people[$person]->id}:{$date}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'workload/index',
            'X-Inertia-Partial-Data' => 'cell',
        ])
        ->assertForbidden();
})->with([
    'una empleada, la de una compañera' => ['elena', 'lucia', '2026-10-15'],
    'un responsable, la de otro departamento' => ['raul', 'pablo', '2026-10-13'],
    'un admin, la de una persona desactivada' => ['ana', 'olga', '2026-10-15'],
]);

it('una recarga parcial de otras props no se rompe por la celda de la URL', function () {
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->people['elena'])
        ->get("/carga?celda={$this->people['lucia']->id}:2026-10-15", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'workload/index',
            'X-Inertia-Partial-Data' => 'matrix,trays',
        ])
        ->assertOk()
        ->assertJsonMissingPath('props.cell');
});

it('se abre con una recarga parcial que solo trae el panel', function () {
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->people['ana'])
        ->get("/carga?celda={$this->people['elena']->id}:2026-10-13", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'workload/index',
            'X-Inertia-Partial-Data' => 'cell',
        ])
        ->assertOk()
        ->assertJsonPath('props.cell.planned', 600)
        ->assertJsonMissingPath('props.matrix')
        ->assertJsonMissingPath('props.trays');
});

it('un empleado ve sus tareas pero no puede reasignarlas; sin ser miembro del proyecto, tampoco editarlas', function () {
    $other = Project::factory()->create(['code' => 'OTRO']);
    Task::factory()->create(['project_id' => $other->id, 'assignee_user_id' => $this->people['elena']->id, 'title' => 'Ajena', 'estimated_minutes' => 240, 'start_date' => '2026-10-15', 'due_date' => '2026-10-15']);

    $cell = ($this->cell)('elena', 'elena', '2026-10-15');
    $tasks = collect($cell['tasks'])->keyBy('title');

    expect($tasks['Maquetar la home'])->toMatchArray(['can_edit' => true, 'assignee_ids' => null])
        ->and($tasks['Ajena'])->toMatchArray(['can_edit' => false, 'assignee_ids' => null]);
});

it('un gestor puede reasignar las tareas de su proyecto entre los miembros, aunque sean de otro departamento', function () {
    $this->tasks['elena_web']->update(['assignee_user_id' => $this->people['sergio']->id]);

    $cell = ($this->cell)('sergio', 'sergio', '2026-10-13');
    $task = $cell['tasks'][0];

    expect($task['title'])->toBe('Maquetar la home')
        ->and($task['assignee_ids'])->toEqualCanonicalizing([
            $this->people['sergio']->id, $this->people['elena']->id, $this->people['lucia']->id, $this->people['pablo']->id,
        ])
        // Fuera de su alcance: solo el nombre (nunca su carga).
        ->and(collect($cell['extra_people'])->pluck('name')->sort()->values()->all())->toBe(['Elena Empleada', 'Lucía Martín', 'Pablo Ruiz'])
        ->and($cell['extra_people'][0])->toHaveKeys(['id', 'name', 'department'])
        ->and($cell['extra_people'][0])->not->toHaveKey('planned');
});

it('«puede editar» coincide con TaskPolicy::update para cada tarea del panel y de las bandejas', function (string $who) {
    // Sergio gestiona WEB pero no es miembro de APP.
    foreach (['app' => 'De APP', 'web' => 'De WEB'] as $project => $title) {
        Task::factory()->create(['project_id' => $this->projects[$project]->id, 'assignee_user_id' => $this->people['sergio']->id, 'title' => $title, 'estimated_minutes' => 60, 'start_date' => '2026-10-13', 'due_date' => '2026-10-13']);
    }

    $viewer = User::query()->findOrFail($this->people[$who]->id);
    $checked = 0;

    foreach (['elena', 'lucia', 'pablo', 'raul', 'sergio', 'marta'] as $person) {
        if ($viewer->id !== $this->people[$person]->id && ! $viewer->canSeeHoursOf($this->people[$person])) {
            continue;
        }

        foreach (['2026-10-13', '2026-10-15'] as $date) {
            foreach (($this->cell)($who, $person, $date)['tasks'] as $row) {
                $task = Task::query()->findOrFail($row['id']);
                expect($row['can_edit'])->toBe(Gate::forUser($viewer)->allows('update', $task), "{$who} → {$row['title']}");
                $checked++;
            }
        }
    }

    $this->actingAs($viewer)->get('/carga')->assertInertia(function (Assert $page) use ($viewer, &$checked) {
        $trays = $page->toArray()['props']['trays'];
        $rows = [...$trays['unplanned']['tasks'], ...collect($trays['unassigned']['groups'])->flatMap(fn (array $group) => $group['tasks'])->all()];

        foreach ($rows as $row) {
            expect($row['can_edit'])->toBe(Gate::forUser($viewer)->allows('update', Task::query()->findOrFail($row['id'])));
            $checked++;
        }
    });

    expect($checked)->toBeGreaterThan(0);
})->with(['ana', 'raul', 'marta', 'elena', 'lucia', 'sergio']);
