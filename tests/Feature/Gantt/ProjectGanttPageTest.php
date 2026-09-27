<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pestaña Gantt del proyecto (SPEC §6.1, D-060): props, permisos (D-021, D-031) y rango inicial.
| Mover y enlazar usan las rutas comunes schedule.* (tests/Feature/Schedule).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->project = Project::factory()->create([
        'hourly_rate' => '60.00',
        'start_date' => '2026-10-01',
        'due_date' => '2026-11-30',
    ]);
    $this->url = "/proyectos/{$this->project->id}/gantt";

    $this->actors = [
        'admin' => userWithRole('admin'),
        'department_manager' => userWithRole('department_manager'),
        'manager' => userWithRole('employee'),
        'member' => userWithRole('employee'),
        'outsider' => userWithRole('employee'),
        'client' => userWithRole('client'),
    ];
    $this->project->addMember($this->actors['member']);
    $this->project->addMember($this->actors['manager'], isManager: true);

    $this->as = function (string $actor) {
        if ($actor !== 'guest') {
            $this->actingAs($this->actors[$actor]);
        }

        return $this;
    };
});

dataset('gantt actors', ['guest', 'admin', 'department_manager', 'manager', 'member', 'outsider', 'client']);

test('la ven todos los internos; los invitados van al login y los clientes a su portal', function (string $actor) {
    ($this->as)($actor);

    $response = $this->get($this->url);

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/gantt')),
    };
})->with('gantt actors');

test('can.update y can.create siguen TaskPolicy: miembros y quien gestiona el proyecto', function (string $actor) {
    $task = Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-10-09']);
    $expected = in_array($actor, ['admin', 'department_manager', 'manager', 'member'], true);

    expect(Gate::forUser($this->actors[$actor])->allows('update', $task))->toBe($expected);

    ($this->as)($actor)->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.update', $expected)
            ->where('can.create', $expected)
            ->where('tasks.0.can.update', $expected)
            ->where('canManage', in_array($actor, ['admin', 'department_manager', 'manager'], true)));
})->with(['admin', 'department_manager', 'manager', 'member', 'outsider']);

it('en un proyecto archivado no se crean tareas', function () {
    $this->project->update(['status' => 'archived']);

    $this->actingAs($this->actors['member'])->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.create', false));
});

it('envía las tareas con estado, responsable, estimación efectiva, horas y subtareas', function () {
    $elena = userWithRole('employee', ['name' => 'Elena Empleada']);
    $parent = Task::factory()->assignedTo($elena)->create([
        'project_id' => $this->project->id, 'title' => 'Diseño', 'start_date' => '2026-10-05', 'due_date' => '2026-10-09',
        'estimated_minutes' => 600, 'position' => 0,
    ]);
    $child = Task::factory()->subtaskOf($parent)->create(['title' => 'Bocetos', 'start_date' => '2026-10-05', 'due_date' => '2026-10-06', 'estimated_minutes' => 120]);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Sin estimar', 'due_date' => '2026-10-07']);
    $milestone = Task::factory()->milestone()->create(['project_id' => $this->project->id, 'title' => 'Entrega', 'due_date' => '2026-10-16', 'position' => 2]);
    $undated = Task::factory()->completed()->create(['project_id' => $this->project->id, 'title' => 'Sin fechas', 'position' => 3]);
    TimeEntry::factory()->forTask($parent)->minutes(30)->create();
    TimeEntry::factory()->forTask($child)->minutes(45)->create();

    $todo = TaskStatus::defaultStatus();

    $this->actingAs($this->actors['member'])->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/gantt')
            ->where('project.id', $this->project->id)
            ->missing('project.hourly_rate')
            ->missing('project.fixed_price_amount')
            ->has('tasks', 5)
            ->where('tasks.0.id', $parent->id)
            ->where('tasks.0.title', 'Diseño')
            ->where('tasks.0.start_date', '2026-10-05')
            ->where('tasks.0.due_date', '2026-10-09')
            ->where('tasks.0.parent_task_id', null)
            ->where('tasks.0.status', ['id' => $todo->id, 'name' => $todo->name, 'color' => $todo->color, 'category' => 'todo'])
            ->where('tasks.0.assignee.id', $elena->id)
            ->where('tasks.0.assignee.name', 'Elena Empleada')
            ->where('tasks.0.estimated_minutes', 120)
            ->where('tasks.0.logged_minutes', 75)
            ->where('tasks.0.subtasks_count', 2)
            ->where('tasks.0.is_milestone', false)
            ->where('tasks.0.is_completed', false)
            ->where('tasks.0.can.update', true)
            ->where('tasks.1.id', $child->id)
            ->where('tasks.1.parent_task_id', $parent->id)
            ->where('tasks.1.logged_minutes', 45)
            ->where('tasks.3.id', $milestone->id)
            ->where('tasks.3.is_milestone', true)
            ->where('tasks.3.start_date', null)
            ->where('tasks.3.estimated_minutes', null)
            ->where('tasks.4.id', $undated->id)
            ->where('tasks.4.start_date', null)
            ->where('tasks.4.due_date', null)
            ->where('tasks.4.is_completed', true)
            ->has('statuses', 5)
            ->where('today', LocalTime::todayString())
            ->where('preferences', ['scale' => 'week', 'color' => 'status'])
            ->where('banks', []));
});

it('las dependencias no incluyen tareas en la papelera ni de otros proyectos', function () {
    $a = Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-10-07']);
    $b = Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-10-09']);
    $c = Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-10-12']);
    $link = TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);
    TaskDependency::query()->create(['predecessor_task_id' => $b->id, 'successor_task_id' => $c->id]);
    $c->delete();

    $other = Task::factory()->create(['due_date' => '2026-10-07']);
    $otherNext = Task::factory()->create(['project_id' => $other->project_id, 'due_date' => '2026-10-08']);
    TaskDependency::query()->create(['predecessor_task_id' => $other->id, 'successor_task_id' => $otherNext->id]);

    $this->actingAs($this->actors['outsider'])->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 2)
            ->where('dependencies', [[
                'id' => $link->id,
                'predecessor_task_id' => $a->id,
                'successor_task_id' => $b->id,
                'type' => 'finish_to_start',
            ]])
            ->where('tasks.0.can.update', false));
});

it('el rango va de la primera a la última fecha (tareas, proyecto y hoy) con margen', function () {
    $this->travelTo('2026-10-20 10:00:00');
    Task::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-09-20', 'due_date' => '2026-10-02']);
    Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-12-10']);

    $this->actingAs($this->actors['member'])->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('today', '2026-10-20')
            ->where('range', ['start' => '2026-09-13', 'end' => '2026-12-24']));

    // Sin tareas con fechas manda el proyecto (y hoy).
    Task::query()->delete();
    $this->actingAs($this->actors['member'])->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->where('range', ['start' => '2026-09-24', 'end' => '2026-12-14']));

    // Sin ninguna fecha: de una semana antes de hoy a un mes después.
    $this->project->update(['start_date' => null, 'due_date' => null]);
    $this->actingAs($this->actors['member'])->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->where('range', ['start' => '2026-10-13', 'end' => '2026-11-19']));
});

it('en un proyecto de bolsas envía solo las bolsas abiertas para crear tareas', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->actors['member']);
    $open = HourBank::factory()->forDepartment($design)->create(['project_id' => $project->id, 'name' => 'Abierta']);
    HourBank::factory()->closed()->create(['project_id' => $project->id, 'name' => 'Cerrada']);

    $this->actingAs($this->actors['member'])->get("/proyectos/{$project->id}/gantt")
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks', 1)
            ->where('banks.0.id', $open->id)
            ->where('banks.0.is_open', true)
            ->where('banks.0.department.name', 'Diseño'));

    // Quien no puede crear tareas no recibe bolsas.
    $this->actingAs($this->actors['outsider'])->get("/proyectos/{$project->id}/gantt")
        ->assertInertia(fn (Assert $page) => $page->where('banks', []));
});

it('lee la escala y el color de la URL e ignora los valores desconocidos', function () {
    $this->actingAs($this->actors['member'])->get($this->url.'?escala=dia&color=responsable')
        ->assertInertia(fn (Assert $page) => $page->where('preferences', ['scale' => 'day', 'color' => 'assignee']));

    $this->actingAs($this->actors['member'])->get($this->url.'?escala=mes')
        ->assertInertia(fn (Assert $page) => $page->where('preferences', ['scale' => 'month', 'color' => 'status']));

    $this->actingAs($this->actors['member'])->get($this->url.'?escala=anio&color[]=x')
        ->assertInertia(fn (Assert $page) => $page->where('preferences', ['scale' => 'week', 'color' => 'status']));
});

it('recarga solo las props que se piden (recarga parcial)', function () {
    Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-10-09']);

    $this->actingAs($this->actors['member'])
        ->get($this->url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'projects/gantt',
            'X-Inertia-Partial-Data' => 'tasks,dependencies,range',
        ])
        ->assertOk()
        ->assertJsonCount(1, 'props.tasks')
        ->assertJsonPath('props.range.end', fn (string $end): bool => $end !== '')
        ->assertJsonMissingPath('props.statuses')
        ->assertJsonMissingPath('props.banks');
});

it('un proyecto que no existe da 404', function () {
    $this->actingAs($this->actors['admin'])->get('/proyectos/999999/gantt')->assertNotFound();
});
