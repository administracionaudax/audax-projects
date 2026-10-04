<?php

use App\Domain\Calendar\TeamCalendar;
use App\Enums\AbsenceType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Calendario del equipo (/calendario, D-144): tareas con fechas de todos los proyectos que se ven,
| en mes, semana y día; vista «Personas» con capacidad, carga y ausencias; filtros; panel de la
| tarea; reprogramar con permiso; colaboradores. «Hoy» es el miércoles 07/10/2026 en Madrid
| (semana del lunes 5 al domingo 11; el mes de octubre va del lunes 28/09 al domingo 01/11).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->admin = userWithRole('admin', ['name' => 'Admin']);
    $this->ana = userWithRole('employee', ['name' => 'Ana Diseño', 'department_id' => $this->design->id]);
    $this->bea = userWithRole('employee', ['name' => 'Bea Desarrollo', 'department_id' => $this->dev->id]);
    $this->client = Client::factory()->create(['name' => 'Hoteles Mediterráneo']);
    $this->project = Project::factory()->withMembers([$this->ana])->create(['code' => 'HOTEL', 'name' => 'Web Hoteles', 'client_id' => $this->client->id, 'owner_user_id' => $this->admin->id]);
    $this->other = Project::factory()->withMembers([$this->bea])->create(['code' => 'ACME', 'name' => 'Web ACME', 'owner_user_id' => $this->admin->id]);

    $this->task = fn (string $title, array $attributes = []): Task => Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => $title,
        ...$attributes,
    ]);
    $this->props = fn (string $query = '', ?User $user = null): array => $this->actingAs($user ?? $this->admin)
        ->get('/calendario'.$query)->assertOk()->viewData('page')['props'];
    $this->titles = fn (string $query = '', ?User $user = null): array => collect(($this->props)($query, $user)['calendar']['tasks'])
        ->pluck('title')->sort()->values()->all();
});

it('enseña la semana de hoy por defecto con las tareas que vencen, empiezan o cruzan el rango', function () {
    ($this->task)('Vence el lunes', ['due_date' => '2026-10-05']);
    ($this->task)('Vence el domingo', ['due_date' => '2026-10-11']);
    ($this->task)('Empieza el jueves, sin entrega', ['start_date' => '2026-10-08']);
    ($this->task)('Cruza la semana', ['start_date' => '2026-09-20', 'due_date' => '2026-10-20']);
    ($this->task)('Empieza antes y vence dentro', ['start_date' => '2026-09-30', 'due_date' => '2026-10-06']);
    ($this->task)('Empieza dentro y vence después', ['start_date' => '2026-10-09', 'due_date' => '2026-10-15']);
    ($this->task)('Vence la semana anterior', ['due_date' => '2026-10-04']);
    ($this->task)('Vence la semana siguiente', ['due_date' => '2026-10-12']);
    ($this->task)('Sin fechas');
    ($this->task)('Hecha', ['due_date' => '2026-10-06', 'status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
    Task::factory()->create(['project_id' => Project::factory()->archived()->create()->id, 'title' => 'Archivada', 'due_date' => '2026-10-06']);

    $props = ($this->props)();

    expect($props['filters']['view'])->toBe('week')
        ->and($props['calendar']['from'])->toBe('2026-10-05')
        ->and($props['calendar']['to'])->toBe('2026-10-11')
        ->and($props['calendar']['today'])->toBe('2026-10-07')
        ->and(collect($props['calendar']['tasks'])->pluck('title')->sort()->values()->all())->toBe([
            'Cruza la semana',
            'Empieza antes y vence dentro',
            'Empieza dentro y vence después',
            'Empieza el jueves, sin entrega',
            'Vence el domingo',
            'Vence el lunes',
        ])
        ->and(($this->titles)('?hechas=1'))->toContain('Hecha');
});

it('acota el mes (semanas completas) y el día', function () {
    ($this->task)('28 de septiembre', ['due_date' => '2026-09-28']);
    ($this->task)('1 de noviembre', ['due_date' => '2026-11-01']);
    ($this->task)('2 de noviembre', ['due_date' => '2026-11-02']);
    ($this->task)('Hoy', ['due_date' => '2026-10-07']);
    ($this->task)('Mañana', ['due_date' => '2026-10-08']);
    ($this->task)('Hito de hoy', ['due_date' => '2026-10-07', 'is_milestone' => true]);

    $month = ($this->props)('?vista=mes&fecha=2026-10-20')['calendar'];
    $day = ($this->props)('?vista=dia&fecha=2026-10-07')['calendar'];

    expect([$month['from'], $month['to']])->toBe(['2026-09-28', '2026-11-01'])
        ->and(collect($month['tasks'])->pluck('title')->sort()->values()->all())->toBe(['1 de noviembre', '28 de septiembre', 'Hito de hoy', 'Hoy', 'Mañana'])
        ->and([$day['from'], $day['to']])->toBe(['2026-10-07', '2026-10-07'])
        ->and(collect($day['tasks'])->pluck('title')->sort()->values()->all())->toBe(['Hito de hoy', 'Hoy'])
        ->and(collect($day['tasks'])->firstWhere('title', 'Hito de hoy')['is_milestone'])->toBeTrue()
        // Una fecha o una vista que no valen: la semana de hoy.
        ->and(($this->props)('?vista=anio&fecha=2026-13-45')['calendar']['from'])->toBe('2026-10-05');
});

it('envía el proyecto (código y color), el responsable y la tarea padre sin N+1', function () {
    $parent = ($this->task)('Padre', ['due_date' => '2026-10-09']);
    Task::factory()->subtaskOf($parent)->assignedTo($this->ana)->create(['title' => 'Subtarea', 'due_date' => '2026-10-08']);

    $calendar = ($this->props)()['calendar'];
    $subtask = collect($calendar['tasks'])->firstWhere('title', 'Subtarea');

    expect($subtask['parent_title'])->toBe('Padre')
        ->and($subtask['assignee_id'])->toBe($this->ana->id)
        ->and(collect($calendar['assignees'])->pluck('name')->all())->toBe(['Ana Diseño'])
        ->and($calendar['projects'])->toHaveCount(1)
        ->and($calendar['projects'][0])->toMatchArray(['code' => 'HOTEL', 'name' => 'Web Hoteles', 'can_update' => true])
        ->and($calendar['projects'][0]['color'])->toBe($this->project->color);
});

it('filtra por persona, departamento, proyecto, cliente, prioridad, tipo, hitos, sin asignar, mías y texto', function () {
    $type = TaskType::factory()->create(['name' => 'Maquetación']);
    ($this->task)('De Ana', ['assignee_user_id' => $this->ana->id, 'due_date' => '2026-10-06', 'priority' => 'high', 'task_type_id' => $type->id]);
    Task::factory()->create(['project_id' => $this->other->id, 'title' => 'De Bea', 'assignee_user_id' => $this->bea->id, 'due_date' => '2026-10-06']);
    ($this->task)('Sin responsable', ['due_date' => '2026-10-07']);
    ($this->task)('Hito', ['due_date' => '2026-10-08', 'is_milestone' => true, 'assignee_user_id' => $this->admin->id]);

    expect(($this->titles)("?persona={$this->ana->id},{$this->bea->id}"))->toBe(['De Ana', 'De Bea'])
        ->and(($this->titles)("?departamento={$this->dev->id}"))->toBe(['De Bea'])
        ->and(($this->titles)("?proyecto={$this->other->id}"))->toBe(['De Bea'])
        ->and(($this->titles)("?cliente={$this->client->id}"))->toBe(['De Ana', 'Hito', 'Sin responsable'])
        ->and(($this->titles)('?prioridad=high'))->toBe(['De Ana'])
        ->and(($this->titles)("?tipo={$type->id}"))->toBe(['De Ana'])
        ->and(($this->titles)('?hitos=1'))->toBe(['Hito'])
        ->and(($this->titles)('?sin_asignar=1'))->toBe(['Sin responsable'])
        ->and(($this->titles)("?sin_asignar=1&persona={$this->bea->id}"))->toBe(['De Bea', 'Sin responsable'])
        ->and(($this->titles)('?mias=1'))->toBe(['Hito'])
        ->and(($this->titles)('?q=acme'))->toBe(['De Bea'])
        ->and(($this->titles)('?q=mediterr'))->toBe(['De Ana', 'Hito', 'Sin responsable'])
        // Lo que no vale se ignora.
        ->and(($this->titles)('?persona=abc&prioridad=muy'))->toHaveCount(4);
});

it('avisa si hay más tareas de las que caben y pide filtrar', function () {
    $status = TaskStatus::defaultStatus()->id;
    $rows = [];
    foreach (range(1, TeamCalendar::MAX_TASKS + 5) as $i) {
        $rows[] = ['project_id' => $this->project->id, 'title' => "Tarea {$i}", 'status_id' => $status, 'priority' => 'normal', 'due_date' => '2026-10-06', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('tasks')->insert($chunk);
    }

    $calendar = ($this->props)()['calendar'];

    expect($calendar['tasks'])->toHaveCount(TeamCalendar::MAX_TASKS)
        ->and($calendar['truncated'])->toBeTrue()
        ->and($calendar['total'])->toBe(TeamCalendar::MAX_TASKS + 5);
});

it('vista Personas: filas por persona con capacidad, carga, festivos y ausencias según quién mira', function () {
    $this->design->managers()->attach($manager = userWithRole('department_manager', ['name' => 'Responsable Diseño', 'department_id' => $this->design->id]));
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
    Absence::factory()->approved()->between('2026-10-08', '2026-10-09')->create(['user_id' => $this->ana->id, 'type' => AbsenceType::Sick]);
    Absence::factory()->approved()->between('2026-10-07', '2026-10-07')->partial(240)->create(['user_id' => $this->bea->id, 'type' => AbsenceType::Training]);
    // 8 horas de Ana repartidas entre hoy y el viernes: hoy es el único día laborable con capacidad
    // (jueves y viernes está de baja), así que todo va a hoy.
    ($this->task)('Informe', ['assignee_user_id' => $this->ana->id, 'estimated_minutes' => 480, 'due_date' => '2026-10-09']);

    $rows = fn (User $viewer, string $query = '?personas=1'): array => collect(($this->props)($query, $viewer)['calendar']['rows'])
        ->keyBy(fn (array $row): string => $row['person']['name'] ?? 'sin-asignar')->all();

    $asAdmin = $rows($this->admin);
    expect(array_keys($asAdmin))->toBe(['Admin', 'Ana Diseño', 'Bea Desarrollo', 'Responsable Diseño', 'sin-asignar'])
        ->and($asAdmin['Ana Diseño']['person']['department']['name'])->toBe('Diseño')
        ->and($asAdmin['Ana Diseño']['show_load'])->toBeTrue()
        ->and($asAdmin['Ana Diseño']['days']['2026-10-07'])->toBe(['capacity' => 480, 'load' => 480, 'absence' => null, 'holiday' => null])
        ->and($asAdmin['Ana Diseño']['days']['2026-10-08'])->toMatchArray(['capacity' => 0, 'absence' => ['partial' => false, 'label' => 'Baja']])
        // Los días pasados no tienen carga planificada.
        ->and($asAdmin['Ana Diseño']['days']['2026-10-05']['load'])->toBeNull()
        ->and($asAdmin['Bea Desarrollo']['days']['2026-10-07'])->toMatchArray(['capacity' => 240, 'absence' => ['partial' => true, 'label' => 'Formación externa']])
        ->and(($this->props)('?personas=1&fecha=2026-10-12', $this->admin)['calendar']['rows'][0]['days']['2026-10-12']['holiday'])->toBe('Fiesta Nacional de España');

    // La responsable de Diseño ve la carga y el tipo de ausencia de Ana, no los de Bea.
    $asManager = $rows($manager);
    expect($asManager['Ana Diseño']['days']['2026-10-08']['absence'])->toBe(['partial' => false, 'label' => 'Baja'])
        ->and($asManager['Ana Diseño']['show_load'])->toBeTrue()
        ->and($asManager['Bea Desarrollo']['show_load'])->toBeFalse()
        ->and($asManager['Bea Desarrollo']['days']['2026-10-07'])->toBe(['capacity' => null, 'load' => null, 'absence' => ['partial' => true, 'label' => null], 'holiday' => null]);

    // Bea solo ve su carga; de Ana sabe que no está, pero no por qué.
    $asBea = $rows($this->bea);
    expect($asBea['Bea Desarrollo']['show_load'])->toBeTrue()
        ->and($asBea['Ana Diseño']['show_load'])->toBeFalse()
        ->and($asBea['Ana Diseño']['days']['2026-10-08'])->toBe(['capacity' => null, 'load' => null, 'absence' => ['partial' => false, 'label' => null], 'holiday' => null]);

    // Filas acotadas por los filtros de persona, departamento y «mías»; el mes no tiene filas.
    expect(array_keys($rows($this->admin, "?personas=1&persona={$this->bea->id}")))->toBe(['Bea Desarrollo'])
        ->and(array_keys($rows($this->admin, "?personas=1&departamento={$this->design->id}")))->toBe(['Ana Diseño', 'Responsable Diseño'])
        ->and(array_keys($rows($this->admin, '?personas=1&mias=1')))->toBe(['Admin'])
        ->and(array_keys($rows($this->admin, '?personas=1&sin_asignar=1')))->toBe(['sin-asignar'])
        ->and(($this->props)('?vista=mes&personas=1')['calendar']['rows'])->toBe([])
        ->and(($this->props)('?vista=dia&personas=1')['calendar']['rows'][1]['days'])->toHaveKeys(['2026-10-07']);
});

it('un colaborador externo solo ve las tareas y las personas de sus proyectos, sin cargas ni ausencias', function () {
    $sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->project->addMember($sara);
    ($this->task)('Tarea Hoteles', ['due_date' => '2026-10-06', 'assignee_user_id' => $sara->id]);
    Task::factory()->create(['project_id' => $this->other->id, 'title' => 'Tarea ACME', 'due_date' => '2026-10-06']);
    Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $this->ana->id]);

    $props = ($this->props)('?personas=1', $sara);
    $rows = collect($props['calendar']['rows'])->keyBy(fn (array $row): string => $row['person']['name'] ?? 'sin-asignar');

    expect(collect($props['calendar']['tasks'])->pluck('title')->all())->toBe(['Tarea Hoteles'])
        ->and(($this->titles)("?proyecto={$this->other->id}", $sara))->toBe([])
        ->and(collect($props['options']['people'])->pluck('name')->all())->toBe(['Admin', 'Ana Diseño', 'Sara Colaboradora'])
        ->and($props['options']['departments'])->toBe([])
        ->and(collect($props['options']['projects'])->pluck('code')->all())->toBe(['HOTEL'])
        ->and($rows->keys()->all())->toBe(['Admin', 'Ana Diseño', 'Sara Colaboradora', 'sin-asignar'])
        ->and($rows['Ana Diseño']['days']['2026-10-06']['absence'])->toBeNull()
        ->and($rows['Sara Colaboradora']['show_load'])->toBeFalse()
        // Un departamento en la URL no le filtra (no los ve).
        ->and(($this->props)("?departamento={$this->design->id}", $sara)['filters']['department'])->toBeNull();
});

it('abre el panel de la tarea sin salir del calendario, con lo que necesita de su proyecto', function () {
    $task = ($this->task)('Diseñar la portada', ['due_date' => '2026-10-06']);
    $foreign = Task::factory()->create(['project_id' => $this->other->id, 'due_date' => '2026-10-06']);
    $sara = User::factory()->collaborator()->create();
    $this->project->addMember($sara);

    $this->actingAs($this->ana)->get("/calendario?tarea={$task->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')
            ->where('panel.task.id', $task->id)
            ->where('panel.can.update', true)
            ->where('panelLookups.project.code', 'HOTEL')
            ->where('panelLookups.can.update', true)
            ->has('panelLookups.statuses', 5)
            ->has('panelLookups.users')
            ->where('panelLookups.currentUser.id', $this->ana->id));

    // Una tarea que no puede ver (o que no existe) no abre nada.
    expect(($this->props)("?tarea={$foreign->id}", $sara)['panel'])->toBeNull()
        ->and(($this->props)('?tarea=999999', $sara)['panelLookups'])->toBeNull()
        ->and(($this->props)("?tarea={$foreign->id}", $this->ana)['panelLookups']['can']['update'])->toBeFalse();
});

it('reprograma al arrastrar solo con permiso de edición, con la propuesta de dependencias', function () {
    $task = ($this->task)('Maquetar', ['start_date' => '2026-10-05', 'due_date' => '2026-10-07']);
    $successor = ($this->task)('Revisar', ['start_date' => '2026-10-08', 'due_date' => '2026-10-09']);
    TaskDependency::query()->create(['predecessor_task_id' => $task->id, 'successor_task_id' => $successor->id]);

    // Bea no es del proyecto: lo ve en solo lectura y no puede moverla.
    $calendar = ($this->props)('', $this->bea)['calendar'];
    expect(collect($calendar['projects'])->firstWhere('code', 'HOTEL')['can_update'])->toBeFalse();
    $this->actingAs($this->bea)
        ->postJson("/tareas/{$task->id}/reprogramar/propuesta", ['start_date' => '2026-10-07', 'due_date' => '2026-10-09'])
        ->assertForbidden();
    $this->actingAs($this->bea)
        ->from('/calendario')
        ->post("/tareas/{$task->id}/reprogramar", ['start_date' => '2026-10-07', 'due_date' => '2026-10-09'])
        ->assertForbidden();

    // Ana sí: la propuesta desplaza a la sucesora y, al confirmar, se mueven las dos.
    $this->actingAs($this->ana)
        ->postJson("/tareas/{$task->id}/reprogramar/propuesta", ['start_date' => '2026-10-07', 'due_date' => '2026-10-09'])
        ->assertOk()
        ->assertJsonPath('proposals.0.task_id', $successor->id);
    $this->actingAs($this->ana)
        ->from('/calendario?vista=semana')
        ->post("/tareas/{$task->id}/reprogramar", ['start_date' => '2026-10-07', 'due_date' => '2026-10-09', 'shift_successors' => true])
        ->assertRedirect('/calendario?vista=semana');

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-09')
        ->and($task->fresh()->start_date->toDateString())->toBe('2026-10-07')
        ->and($successor->fresh()->start_date->toDateString())->toBe('2026-10-10');
});

it('ofrece los proyectos donde puede crear tareas, con sus bolsas abiertas', function () {
    $banks = Project::factory()->hourBank()->withMembers([$this->ana])->create(['code' => 'BOLSA', 'owner_user_id' => $this->admin->id]);
    HourBank::factory()->create(['project_id' => $banks->id, 'name' => 'Bolsa Q4']);
    Project::factory()->archived()->withMembers([$this->ana])->create(['code' => 'VIEJO', 'owner_user_id' => $this->admin->id]);

    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'calendar/index',
        'X-Inertia-Partial-Data' => 'creatable',
    ];
    $creatable = $this->actingAs($this->ana)->get('/calendario', $headers)->assertOk()->json('props.creatable');

    expect(collect($creatable)->pluck('code')->all())->toBe(['BOLSA', 'HOTEL'])
        ->and(collect($creatable)->firstWhere('code', 'BOLSA')['banks'][0]['name'])->toBe('Bolsa Q4')
        // Sin pedirla, no se calcula.
        ->and(($this->props)('', $this->ana))->not->toHaveKey('creatable');
});

it('las tareas tienen índices de inicio y de entrega', function () {
    $indexes = collect(DB::getSchemaBuilder()->getIndexes('tasks'))->pluck('columns')->all();

    expect($indexes)->toContain(['due_date', 'start_date'])
        ->and($indexes)->toContain(['start_date'])
        ->and(collect(DB::getSchemaBuilder()->getIndexes('time_entries'))->pluck('columns')->all())->toContain(['user_id', 'task_id', 'date']);
});
