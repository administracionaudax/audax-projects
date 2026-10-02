<?php

use App\Domain\Planning\CalendarPeriod;
use App\Domain\Planning\TaskCalendar;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Vista Calendario de la pestaña Tareas (D-061): /proyectos/{p}/tareas?vista=calendario&mes=… o
| &semana=…, con los filtros de la pestaña y el panel de la tarea. "Hoy" es el martes 13/10/2026.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Madrid'));

    $this->member = userWithRole('employee', ['name' => 'Elena Empleada']);
    $this->colleague = userWithRole('employee', ['name' => 'Pablo Compañero']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->member);
    $this->project->addMember($this->colleague);
    $this->url = "/proyectos/{$this->project->id}/tareas?vista=calendario";
    $this->task = fn (string $title, ?string $start, ?string $due, array $attributes = []): Task => Task::factory()->create([
        'project_id' => $this->project->id, 'title' => $title, 'start_date' => $start, 'due_date' => $due, ...$attributes,
    ]);
    $this->titles = fn (array $items): array => array_column($items, 'title');
});

it('el mes va de lunes a domingo con semanas completas (octubre de 2026: del 28/09 al 01/11)', function () {
    $this->actingAs($this->member)
        ->get($this->url.'&mes=2026-10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks')
            ->where('view', 'calendar')
            ->where('tasks', [])
            ->where('calendar.mode', 'month')
            ->where('calendar.period', '2026-10')
            ->where('calendar.from', '2026-09-28')
            ->where('calendar.to', '2026-11-01')
            ->where('calendar.today', '2026-10-13'));
});

it('resuelve el periodo: meses de 28 a 31 días, cambio de año, semana y valores no válidos', function (?string $month, ?string $week, string $mode, string $key, string $from, string $to) {
    $period = CalendarPeriod::resolve($month, $week, CarbonImmutable::parse('2026-10-13'));

    expect($period->mode)->toBe($mode)
        ->and($period->key)->toBe($key)
        ->and($period->fromString())->toBe($from)
        ->and($period->toString())->toBe($to);
})->with([
    'febrero de 2027 (28 días, empieza en lunes)' => ['2027-02', null, 'month', '2027-02', '2027-02-01', '2027-02-28'],
    'febrero de 2028 (bisiesto)' => ['2028-02', null, 'month', '2028-02', '2028-01-31', '2028-03-05'],
    'noviembre de 2026 (30 días)' => ['2026-11', null, 'month', '2026-11', '2026-10-26', '2026-12-06'],
    'diciembre de 2026 (cambio de año)' => ['2026-12', null, 'month', '2026-12', '2026-11-30', '2027-01-03'],
    'enero de 2027 (empieza en viernes)' => ['2027-01', null, 'month', '2027-01', '2026-12-28', '2027-01-31'],
    'semana: cualquier día lleva a su lunes' => [null, '2026-10-15', 'week', '2026-10-12', '2026-10-12', '2026-10-18'],
    'semana que cruza el año' => [null, '2027-01-01', 'week', '2026-12-28', '2026-12-28', '2027-01-03'],
    'la semana manda sobre el mes' => ['2026-12', '2026-10-18', 'week', '2026-10-12', '2026-10-12', '2026-10-18'],
    'mes no válido: el de hoy' => ['2026-13', null, 'month', '2026-10', '2026-09-28', '2026-11-01'],
    'fecha imposible: el mes de hoy' => [null, '2026-02-30', 'month', '2026-10', '2026-09-28', '2026-11-01'],
    'año absurdo: el mes de hoy' => ['9999-01', null, 'month', '2026-10', '2026-09-28', '2026-11-01'],
    'sin nada: el mes de hoy' => [null, null, 'month', '2026-10', '2026-09-28', '2026-11-01'],
]);

it('trae las tareas que vencen en el periodo y las que lo cruzan con su franja; las demás, fuera', function () {
    ($this->task)('Vence el 14', null, '2026-10-14');
    ($this->task)('Del 5 al 9', '2026-10-05', '2026-10-09');
    ($this->task)('Empieza antes y vence dentro', '2026-09-20', '2026-09-29');
    ($this->task)('Cruza todo el mes', '2026-09-01', '2026-12-15');
    ($this->task)('Solo inicio', '2026-10-06', null);
    ($this->task)('Vence antes', null, '2026-09-27');
    ($this->task)('Vence después', '2026-11-02', '2026-11-05');
    ($this->task)('Hito', null, '2026-10-30', ['is_milestone' => true]);
    Task::factory()->create(['title' => 'De otro proyecto', 'due_date' => '2026-10-14']);
    ($this->task)('En la papelera', null, '2026-10-14')->delete();

    $this->actingAs($this->member)
        ->get($this->url.'&mes=2026-10')
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.tasks', fn ($tasks) => ($this->titles)($tasks->all()) === [
                'Empieza antes y vence dentro', 'Del 5 al 9', 'Vence el 14', 'Hito', 'Cruza todo el mes',
            ])
            ->where('calendar.tasks.0.start_date', '2026-09-20')
            ->where('calendar.tasks.0.due_date', '2026-09-29')
            ->where('calendar.tasks.3.is_milestone', true)
            ->where('calendar.undated', fn ($tasks) => ($this->titles)($tasks->all()) === ['Solo inicio'])
            ->where('calendar.undated_total', 1));
});

it('en la vista semana solo trae lo de esa semana', function () {
    ($this->task)('Lunes', null, '2026-10-12');
    ($this->task)('Domingo', null, '2026-10-18');
    ($this->task)('Franja desde la anterior', '2026-10-08', '2026-10-13');
    ($this->task)('Semana siguiente', null, '2026-10-19');
    ($this->task)('Semana anterior', null, '2026-10-11');

    $this->actingAs($this->member)
        ->get($this->url.'&semana=2026-10-14')
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.mode', 'week')
            ->where('calendar.period', '2026-10-12')
            ->where('calendar.from', '2026-10-12')
            ->where('calendar.to', '2026-10-18')
            ->where('calendar.tasks', fn ($tasks) => ($this->titles)($tasks->all()) === ['Lunes', 'Franja desde la anterior', 'Domingo']));
});

it('cada tarea lleva estado, responsable, hito y, si es subtarea, el título de su padre; sin datos económicos', function () {
    $parent = ($this->task)('Diseño', '2026-10-01', '2026-10-20', ['assignee_user_id' => $this->member->id, 'estimated_minutes' => 600]);
    Task::factory()->subtaskOf($parent)->assignedTo($this->colleague)->create(['title' => 'Bocetos', 'due_date' => '2026-10-07', 'estimated_minutes' => 120]);

    $this->actingAs($this->member)
        ->get($this->url.'&mes=2026-10')
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendar.tasks', 2)
            ->where('calendar.tasks.0.title', 'Bocetos')
            ->where('calendar.tasks.0.parent_title', 'Diseño')
            ->where('calendar.tasks.0.assignee.name', 'Pablo Compañero')
            ->where('calendar.tasks.0.status_id', TaskStatus::defaultStatus()->id)
            ->where('calendar.tasks.0.is_completed', false)
            ->where('calendar.tasks.1.title', 'Diseño')
            ->where('calendar.tasks.1.parent_title', null)
            ->where('calendar.tasks.1.assignee.name', 'Elena Empleada')
            ->missing('calendar.tasks.1.estimated_minutes')
            ->missing('calendar.tasks.1.hourly_rate'));
});

it('respeta los filtros de la pestaña Tareas: responsable, mías, sin responsable, estado, prioridad, bolsa y completadas', function () {
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();
    $inProgress = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();
    ($this->task)('Mía', null, '2026-10-14', ['assignee_user_id' => $this->member->id]);
    ($this->task)('De Pablo', null, '2026-10-15', ['assignee_user_id' => $this->colleague->id, 'status_id' => $inProgress->id, 'priority' => 'urgent']);
    ($this->task)('Sin nadie', null, '2026-10-16');
    ($this->task)('Hecha', null, '2026-10-17', ['assignee_user_id' => $this->member->id, 'status_id' => $done->id]);
    ($this->task)('Mía sin fecha', null, null, ['assignee_user_id' => $this->member->id]);

    $titles = function (string $query): array {
        $props = $this->actingAs($this->member)->get($this->url.'&mes=2026-10'.$query)->viewData('page')['props'];

        return [
            array_column($props['calendar']['tasks'], 'title'),
            array_column($props['calendar']['undated'], 'title'),
        ];
    };

    expect($titles(''))->toBe([['Mía', 'De Pablo', 'Sin nadie'], ['Mía sin fecha']])
        ->and($titles('&completadas=1'))->toBe([['Mía', 'De Pablo', 'Sin nadie', 'Hecha'], ['Mía sin fecha']])
        ->and($titles('&mias=1'))->toBe([['Mía'], ['Mía sin fecha']])
        ->and($titles("&responsable={$this->colleague->id}"))->toBe([['De Pablo'], []])
        ->and($titles('&responsable=ninguno'))->toBe([['Sin nadie'], []])
        ->and($titles("&estado={$inProgress->id}"))->toBe([['De Pablo'], []])
        ->and($titles('&prioridad=urgent'))->toBe([['De Pablo'], []]);

    $bankProject = Project::factory()->hourBank()->create();
    $bankProject->addMember($this->member);
    $design = HourBank::factory()->hours(20)->create(['project_id' => $bankProject->id]);
    $marketing = HourBank::factory()->hours(20)->create(['project_id' => $bankProject->id]);
    Task::factory()->inBank($design)->create(['title' => 'En Diseño', 'due_date' => '2026-10-14']);
    Task::factory()->inBank($marketing)->create(['title' => 'En Marketing', 'due_date' => '2026-10-14']);

    $this->actingAs($this->member)
        ->get("/proyectos/{$bankProject->id}/tareas?vista=calendario&mes=2026-10&bolsa={$design->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.tasks', fn ($tasks) => ($this->titles)($tasks->all()) === ['En Diseño']));
});

it('la lista «Sin fecha» enseña las 100 más recientes y el total', function () {
    Task::factory()->count(TaskCalendar::UNDATED_LIMIT + 3)->create(['project_id' => $this->project->id, 'due_date' => null]);
    $newest = ($this->task)('La más reciente', null, null);

    $this->actingAs($this->member)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendar.undated', TaskCalendar::UNDATED_LIMIT)
            ->where('calendar.undated.0.id', $newest->id)
            ->where('calendar.undated_total', TaskCalendar::UNDATED_LIMIT + 4));
});

it('fuera de la vista Calendario no calcula el calendario, y en ella no calcula la lista', function () {
    ($this->task)('Vence el 14', null, '2026-10-14');

    $this->actingAs($this->member)->get("/proyectos/{$this->project->id}/tareas")
        ->assertInertia(fn (Assert $page) => $page->where('view', 'list')->where('calendar', null)->has('tasks', 1));
    $this->actingAs($this->member)->get("/proyectos/{$this->project->id}/tareas?vista=kanban")
        ->assertInertia(fn (Assert $page) => $page->where('view', 'kanban')->where('calendar', null));
    $this->actingAs($this->member)->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->where('view', 'calendar')->where('tasks', [])->where('hiddenCompletedCount', 0)->has('calendar.tasks', 1));
});

it('abre el panel de una tarea sobre el calendario (?tarea=) y recarga solo el calendario', function () {
    $task = ($this->task)('Vence el 14', null, '2026-10-14');

    $this->actingAs($this->member)
        ->get($this->url."&mes=2026-10&tarea={$task->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.task.id', $task->id)
            ->where('calendar.tasks.0.id', $task->id));

    $this->actingAs($this->member)
        ->get($this->url.'&mes=2026-10', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'projects/tasks',
            'X-Inertia-Partial-Data' => 'calendar',
        ])
        ->assertOk()
        ->assertJsonPath('props.calendar.tasks.0.id', $task->id)
        ->assertJsonMissingPath('props.statuses');
});

it('lo ven todos los internos; editan (mover fechas) los miembros y quienes gestionan; clientes al portal e invitados al login', function (string $actor, int|string $expected, ?bool $canUpdate) {
    ($this->task)('Vence el 14', null, '2026-10-14');
    $manager = userWithRole('employee');
    $this->project->addMember($manager, isManager: true);
    $users = [
        'admin' => userWithRole('admin'),
        'responsable' => userWithRole('department_manager'),
        'gestor' => $manager,
        'miembro' => $this->member,
        'no miembro' => userWithRole('employee'),
        'cliente' => userWithRole('client'),
    ];

    if ($actor !== 'invitado') {
        $this->actingAs($users[$actor]);
    }

    $response = $this->get($this->url);

    match ($expected) {
        'login' => $response->assertRedirect(route('login')),
        'portal' => $response->assertRedirect(route('portal.home')),
        default => $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('calendar.tasks', 1)
            ->where('can.update', $canUpdate)),
    };
})->with([
    'admin' => ['admin', 200, true],
    'responsable' => ['responsable', 200, true],
    'gestor del proyecto' => ['gestor', 200, true],
    'miembro' => ['miembro', 200, true],
    'empleado no miembro (solo lectura)' => ['no miembro', 200, false],
    'cliente' => ['cliente', 'portal', null],
    'invitado' => ['invitado', 'login', null],
]);

it('el calendario usa pocas consultas y no crece con las tareas (sin N+1)', function () {
    $parent = ($this->task)('Padre', '2026-10-01', '2026-10-20', ['assignee_user_id' => $this->member->id]);
    Task::factory()->subtaskOf($parent)->assignedTo($this->colleague)->create(['due_date' => '2026-10-07']);
    ($this->task)('Sin fecha', null, null, ['assignee_user_id' => $this->colleague->id]);

    $count = function (): int {
        $this->actingAs($this->member)->get($this->url.'&mes=2026-10');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($this->member)->get($this->url.'&mes=2026-10')->assertOk();
        app('events')->forget(QueryExecuted::class);

        return $queries;
    };

    $before = $count();

    foreach (range(1, 6) as $i) {
        $person = User::factory()->employee()->create();
        $this->project->addMember($person);
        $root = ($this->task)("Raíz {$i}", '2026-10-0'.$i, '2026-10-2'.$i, ['assignee_user_id' => $person->id]);
        Task::factory()->subtaskOf($root)->assignedTo($person)->create(['due_date' => '2026-10-1'.$i]);
        ($this->task)("Sin fecha {$i}", null, null, ['assignee_user_id' => $person->id]);
    }

    expect($count())->toBe($before);
});
