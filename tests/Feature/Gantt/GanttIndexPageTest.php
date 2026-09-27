<?php

use App\Domain\Gantt\GanttAccess;
use App\Domain\Gantt\GanttPortfolio;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TaskType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Gantt multiproyecto (/gantt, SPEC §6.1, D-060): filtros en la URL (por defecto, proyectos
| activos), grupos por proyecto, permisos por proyecto (TaskPolicy::update) y el límite de 60
| proyectos o 1.500 tareas.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->acme = Client::factory()->create(['name' => 'Acme']);
    $this->web = Project::factory()->create(['name' => 'Web', 'client_id' => $this->acme->id]);
    $this->mobile = Project::factory()->create(['name' => 'App']);
    $this->paused = Project::factory()->create(['name' => 'Pausado', 'status' => 'on_hold']);
    $this->archived = Project::factory()->archived()->create(['name' => 'Archivado']);

    $this->member = userWithRole('employee');
    $this->web->addMember($this->member);

    $this->taskOf = fn (Project $project, array $attributes = []) => Task::factory()->create([
        'project_id' => $project->id, 'start_date' => '2026-10-05', 'due_date' => '2026-10-09', ...$attributes,
    ]);
});

dataset('portfolio actors', ['guest', 'admin', 'department_manager', 'employee', 'client']);

test('lo ven todos los internos; los invitados van al login y los clientes a su portal', function (string $actor) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get('/gantt');

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('gantt/index')),
    };
})->with('portfolio actors');

it('por defecto enseña los proyectos activos, por nombre, con sus tareas y dependencias', function () {
    $a = ($this->taskOf)($this->web, ['title' => 'Diseño']);
    $b = ($this->taskOf)($this->web, ['title' => 'Maquetación', 'start_date' => '2026-10-12', 'due_date' => '2026-10-14']);
    ($this->taskOf)($this->mobile, ['title' => 'API']);
    ($this->taskOf)($this->paused, ['title' => 'En pausa']);
    $link = TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);

    $this->actingAs($this->member)->get('/gantt')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gantt/index')
            ->where('filters', ['cliente' => null, 'departamento' => null, 'responsable' => null, 'estado' => 'active'])
            ->where('limit.exceeded', null)
            ->where('limit.projects', 2)
            ->where('limit.tasks', 3)
            ->where('limit.max_projects', 60)
            ->where('limit.max_tasks', 1500)
            ->has('projects', 2)
            ->where('projects.0.name', 'App')
            ->where('projects.0.can.update', false)
            ->where('projects.1.name', 'Web')
            ->where('projects.1.client.name', 'Acme')
            ->where('projects.1.can.update', true)
            ->missing('projects.1.hourly_rate')
            ->has('tasks', 3)
            ->where('dependencies.0.id', $link->id)
            ->has('dependencies', 1)
            ->has('options.clients')
            ->has('options.departments')
            ->has('options.owners')
            ->has('statuses', 5));
});

it('filtra por estado, cliente, responsable y departamento implicado', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    $dev = Department::factory()->create(['name' => 'Desarrollo']);
    $owner = userWithRole('employee', ['name' => 'Olga Gestora']);
    $this->mobile->update(['owner_user_id' => $owner->id]);

    $byMember = Project::factory()->create(['name' => 'Por miembro']);
    $byMember->addMember(userWithRole('employee', ['department_id' => $design->id]));
    $byBank = Project::factory()->hourBank()->create(['name' => 'Por bolsa']);
    HourBank::factory()->forDepartment($design)->create(['project_id' => $byBank->id]);
    $byType = Project::factory()->create(['name' => 'Por tipo']);
    ($this->taskOf)($byType, ['task_type_id' => TaskType::factory()->create(['department_id' => $design->id])->id]);
    $otherDept = Project::factory()->create(['name' => 'Otro departamento']);
    $otherDept->addMember(userWithRole('employee', ['department_id' => $dev->id]));

    $names = fn (string $query): array => collect($this->actingAs($this->member)->get('/gantt'.$query)->viewData('page')['props']['projects'])
        ->pluck('name')->all();

    expect($names('?estado=on_hold'))->toBe(['Pausado'])
        ->and($names('?estado=archived'))->toBe(['Archivado'])
        ->and($names('?estado=sin-archivar'))->not->toContain('Archivado')->toContain('Pausado')->toContain('Web')
        ->and($names('?estado=todos'))->toContain('Archivado')->toContain('Pausado')
        ->and($names('?estado=inventado'))->not->toContain('Pausado')->toContain('Web')
        ->and($names("?cliente={$this->acme->id}"))->toBe(['Web'])
        ->and($names("?responsable={$owner->id}"))->toBe(['App'])
        ->and($names("?departamento={$design->id}"))->toBe(['Por bolsa', 'Por miembro', 'Por tipo']);

    $this->actingAs($this->member)->get("/gantt?cliente={$this->acme->id}&estado=todos")
        ->assertInertia(fn (Assert $page) => $page->where('filters', [
            'cliente' => $this->acme->id, 'departamento' => null, 'responsable' => null, 'estado' => 'todos',
        ]));
});

it('can.update de cada proyecto coincide con TaskPolicy para cada rol', function (string $role, bool $member, bool $manager) {
    $user = userWithRole($role);
    if ($member || $manager) {
        $this->web->addMember($user, isManager: $manager);
    }

    $editable = app(GanttAccess::class)->editable($user, [$this->web->id, $this->mobile->id]);

    foreach ([$this->web, $this->mobile] as $project) {
        $task = ($this->taskOf)($project);
        expect($editable[$project->id])->toBe(Gate::forUser($user)->allows('update', $task));
    }

    $this->actingAs($user)->get('/gantt')
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.1.can.update', $editable[$this->web->id])
            ->where('projects.0.can.update', $editable[$this->mobile->id])
            ->where('tasks.0.can.update', $editable[$this->web->id]));
})->with([
    'admin' => ['admin', false, false],
    'responsable' => ['department_manager', false, false],
    'gestor' => ['employee', false, true],
    'miembro' => ['employee', true, false],
    'empleado no miembro' => ['employee', false, false],
]);

it('can.create de cada proyecto coincide con TaskPolicy::create para cada rol (y nunca en archivados)', function (string $role, bool $member, bool $manager) {
    $user = userWithRole($role);
    if ($member || $manager) {
        $this->web->addMember($user, isManager: $manager);
        $this->archived->addMember($user, isManager: $manager);
    }

    $page = $this->actingAs($user)->get('/gantt?estado=todos')->viewData('page')['props'];
    $projects = collect($page['projects'])->keyBy('id');

    foreach ([$this->web, $this->mobile, $this->paused, $this->archived] as $project) {
        expect($projects[$project->id]['can']['create'])
            ->toBe(Gate::forUser($user)->allows('create', [Task::class, $project->fresh()]), $project->name);
    }

    expect($projects[$this->archived->id]['can']['create'])->toBeFalse()
        ->and($page['currentUser'])->toBe(['id' => $user->id, 'department_id' => $user->department_id]);
})->with([
    'admin' => ['admin', false, false],
    'responsable' => ['department_manager', false, false],
    'gestor' => ['employee', false, true],
    'miembro' => ['employee', true, false],
    'empleado no miembro' => ['employee', false, false],
]);

it('«Nueva tarea»: las bolsas abiertas de los proyectos de bolsas donde puede crear, primero las de su departamento y sin importes', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    $dev = Department::factory()->create(['name' => 'Desarrollo']);
    $member = userWithRole('employee', ['department_id' => $design->id]);

    $banked = Project::factory()->hourBank()->create(['name' => 'Bolsas']);
    $banked->addMember($member);
    $older = HourBank::factory()->forDepartment($dev)->create(['project_id' => $banked->id, 'name' => 'Desarrollo', 'start_date' => '2026-01-01', 'hourly_rate' => 60]);
    $mine = HourBank::factory()->forDepartment($design)->create(['project_id' => $banked->id, 'name' => 'Diseño', 'start_date' => '2026-06-01']);
    HourBank::factory()->closed()->create(['project_id' => $banked->id, 'name' => 'Cerrada']);
    HourBank::factory()->renewed()->create(['project_id' => $banked->id, 'name' => 'Renovada']);

    // Sin permiso para crear (no es miembro ni lo gestiona): no se mandan sus bolsas.
    $foreign = Project::factory()->hourBank()->create(['name' => 'Ajeno']);
    HourBank::factory()->create(['project_id' => $foreign->id]);

    $this->actingAs($member)->get('/gantt')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks', 1)
            ->where('banks.0.project_id', $banked->id)
            ->has('banks.0.banks', 2)
            ->where('banks.0.banks.0.id', $mine->id)
            ->where('banks.0.banks.0.department.name', 'Diseño')
            ->where('banks.0.banks.0.is_open', true)
            ->where('banks.0.banks.1.id', $older->id)
            ->missing('banks.0.banks.1.hourly_rate')
            ->missing('banks.0.banks.1.price_amount'));

    // Un proyecto sin bolsas o un usuario que no puede crear: ninguna.
    $this->actingAs(userWithRole('employee'))->get('/gantt')
        ->assertInertia(fn (Assert $page) => $page->where('banks', []));
});

it('un cliente nunca puede editar desde el Gantt', function () {
    $client = userWithRole('client');

    expect(app(GanttAccess::class)->editable($client, [$this->web->id]))->toBe([$this->web->id => false]);
});

it('las dependencias no incluyen tareas en la papelera', function () {
    $a = ($this->taskOf)($this->web);
    $b = ($this->taskOf)($this->web);
    TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);
    $b->delete();

    $this->actingAs($this->member)->get('/gantt')
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('dependencies', []));
});

it('con más de 60 proyectos avisa y no carga las tareas', function () {
    Project::factory()->count(GanttPortfolio::MAX_PROJECTS - 1)->create();
    ($this->taskOf)($this->web);

    $this->actingAs($this->member)->get('/gantt')
        ->assertInertia(fn (Assert $page) => $page
            ->where('limit.exceeded', 'projects')
            ->where('limit.projects', 61)
            ->where('projects', [])
            ->where('tasks', [])
            ->where('dependencies', []));

    // Filtrando, vuelve a cargarse.
    $this->actingAs($this->member)->get("/gantt?cliente={$this->acme->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('limit.exceeded', null)
            ->has('projects', 1)
            ->has('tasks', 1));
});

it('con más de 1.500 tareas avisa y no carga las tareas', function () {
    $statusId = TaskStatus::defaultStatus()->id;
    $now = now();
    $rows = [];
    for ($i = 0; $i < GanttPortfolio::MAX_TASKS + 1; $i++) {
        $rows[] = [
            'project_id' => $i % 2 === 0 ? $this->web->id : $this->mobile->id,
            'title' => "Tarea {$i}",
            'status_id' => $statusId,
            'priority' => 'normal',
            'is_billable' => true,
            'is_milestone' => false,
            'position' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('tasks')->insert($chunk);
    }

    $this->actingAs($this->member)->get('/gantt')
        ->assertInertia(fn (Assert $page) => $page
            ->where('limit.exceeded', 'tasks')
            ->where('limit.tasks', 1501)
            ->where('projects', [])
            ->where('tasks', []));

    $this->actingAs($this->member)->get("/gantt?cliente={$this->acme->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('limit.exceeded', null)
            ->has('tasks', 751));
});

it('sin proyectos que cumplan los filtros no falla', function () {
    $this->actingAs($this->member)->get('/gantt?cliente=999999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('limit.exceeded', null)
            ->where('limit.projects', 0)
            ->where('projects', [])
            ->has('range.start'));
});
