<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\AbsenceType;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Hoja semanal /horas?semana=2026-W39 (SPEC §7, D-021, D-036). "Hoy" es el viernes 25/09/2026.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->department = Department::factory()->create(['name' => 'Diseño']);
    $this->employee = User::factory()->employee()->inDepartment($this->department)->create(['name' => 'Pablo']);
    $this->web = Project::factory()->create(['code' => 'ACME-WEB']);
    $this->mobileApp = Project::factory()->create(['code' => 'BETA-APP']);
    $this->web->addMember($this->employee);
    $this->mobileApp->addMember($this->employee);
    $this->home = Task::factory()->create(['project_id' => $this->web->id, 'title' => 'Home']);
    $this->menu = Task::factory()->create(['project_id' => $this->web->id, 'title' => 'Menú']);
    $this->api = Task::factory()->create(['project_id' => $this->mobileApp->id, 'title' => 'API']);

    $this->log = fn (Task $task, string $date, int $minutes, ?User $user = null): TimeEntry => TimeEntry::factory()
        ->forTask($task)->on($date)->minutes($minutes)->create(['user_id' => ($user ?? $this->employee)->id]);
});

it('cuadra con las entradas: filas por tarea, celdas por día y totales frente a la capacidad', function () {
    WorkSchedule::factory()->for($this->employee)->create(['valid_from' => '2026-01-01', 'fri_minutes' => 360]);
    ($this->log)($this->home, '2026-09-21', 120);
    ($this->log)($this->home, '2026-09-21', 30);
    ($this->log)($this->home, '2026-09-25', 60);
    ($this->log)($this->api, '2026-09-22', 240);
    ($this->log)($this->menu, '2026-09-27', 15);
    // Fuera de la semana y de otra persona: no cuentan.
    ($this->log)($this->home, '2026-09-20', 45);
    ($this->log)($this->home, '2026-09-22', 45, User::factory()->create());

    $response = $this->actingAs($this->employee)->get('/horas?semana=2026-W39')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('time/index', false)
        ->where('week.iso', '2026-W39')
        ->where('week.start', '2026-09-21')
        ->where('week.end', '2026-09-27')
        ->where('week.previous', '2026-W38')
        ->where('week.next', '2026-W40')
        ->where('week.current', '2026-W39')
        ->where('is_own', true)
        ->where('scope', 'full')
        ->where('people', [])
        ->has('rows', 3)
        // Orden: proyecto (código) y tarea.
        ->where('rows.0.task.title', 'Home')
        ->where('rows.1.task.title', 'Menú')
        ->where('rows.2.task.title', 'API')
        ->where('rows.0.total', 210)
        ->has('rows.0.cells', 7)
        ->has('rows.0.cells.0', 2)
        ->where('rows.0.cells.0.0.minutes', 120)
        ->has('rows.0.cells.4', 1)
        ->where('rows.2.cells.1.0.minutes', 240)
        ->where('rows.1.cells.6.0.minutes', 15)
        ->where('totals.days', [
            '2026-09-21' => 150, '2026-09-22' => 240, '2026-09-23' => 0, '2026-09-24' => 0,
            '2026-09-25' => 60, '2026-09-26' => 0, '2026-09-27' => 15,
        ])
        ->where('totals.week', 465)
        ->where('capacity.days.2026-09-25', 360)
        ->where('capacity.week', 480 * 4 + 360)
        ->where('period.status', 'open')
        ->where('period.id', null)
        ->where('can.edit', true)
        ->where('can.submit', true)
        ->where('can.withdraw', false)
        ->where('can.review', false)
        ->where('settings.today', '2026-09-25'));

    // La suma de las celdas es exactamente la de las entradas de la semana.
    $props = $response->inertiaProps();
    $cells = collect($props['rows'])->flatMap(fn (array $row) => collect($row['cells'])->flatten(1))->sum('minutes');
    expect($cells)->toBe((int) TimeEntry::query()->where('user_id', $this->employee->id)->whereBetween('date', ['2026-09-21', '2026-09-27'])->sum('minutes'))
        ->and($cells)->toBe($props['totals']['week']);
});

it('sin semana o con una no válida muestra la actual', function (string $query) {
    $this->actingAs($this->employee)
        ->get('/horas'.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.iso', '2026-W39'));
})->with(['' => [''], 'no válida' => ['?semana=2026-W99'], 'otro formato' => ['?semana=39']]);

it('ofrece las tareas de la semana anterior para copiarlas como filas sin horas (D-036)', function () {
    ($this->log)($this->home, '2026-09-15', 60);
    ($this->log)($this->api, '2026-09-16', 60);
    ($this->log)($this->api, '2026-09-17', 30);
    // Ya no admiten horas: no se ofrecen.
    $archived = Project::factory()->archived()->create();
    $archived->addMember($this->employee);
    ($this->log)(Task::factory()->create(['project_id' => $archived->id]), '2026-09-15', 60);
    $deleted = Task::factory()->create(['project_id' => $this->web->id]);
    ($this->log)($deleted, '2026-09-15', 60);
    $deleted->delete();

    $this->actingAs($this->employee)
        ->get('/horas?semana=2026-W39')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
            ->has('previous_week_tasks', 2)
            ->where('previous_week_tasks.0.title', 'API')
            ->where('previous_week_tasks.0.project.code', 'BETA-APP')
            ->where('previous_week_tasks.1.title', 'Home'));

    // Copiar no crea horas.
    expect(TimeEntry::query()->whereBetween('date', ['2026-09-21', '2026-09-27'])->count())->toBe(0);
});

it('una semana enviada, aprobada o bloqueada no es editable; abierta o devuelta, sí', function (TimesheetStatus $status, bool $editable) {
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status($status)->create(['review_comment' => $status === TimesheetStatus::Returned ? 'Revisa el lunes' : null]);

    $this->actingAs($this->employee)
        ->get('/horas?semana=2026-W39')
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.status', $status->value)
            ->where('can.edit', $editable)
            ->where('can.submit', $editable)
            ->where('can.withdraw', $status === TimesheetStatus::Submitted)
            ->where('period.review_comment', $status === TimesheetStatus::Returned ? 'Revisa el lunes' : null));
})->with([
    'abierta' => [TimesheetStatus::Open, true],
    'devuelta' => [TimesheetStatus::Returned, true],
    'enviada' => [TimesheetStatus::Submitted, false],
    'aprobada' => [TimesheetStatus::Approved, false],
    'bloqueada' => [TimesheetStatus::Locked, false],
]);

it('no se puede enviar una semana futura', function () {
    $this->actingAs($this->employee)
        ->get('/horas?semana=2026-W40')
        ->assertInertia(fn (Assert $page) => $page->where('can.submit', false)->where('can.edit', true));
});

it('un empleado no ve la hoja de otra persona (403)', function () {
    $colleague = User::factory()->employee()->inDepartment($this->department)->create();

    $this->actingAs($this->employee)->get("/horas?persona={$colleague->id}")->assertForbidden();
});

it('el responsable ve la hoja de su equipo y puede revisarla; no la de otros departamentos', function () {
    $head = User::factory()->departmentManager()->create();
    $this->department->managers()->attach($head);
    ($this->log)($this->home, '2026-09-22', 60);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Submitted)->create();

    $this->actingAs($head)
        ->get("/horas?persona={$this->employee->id}&semana=2026-W39")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_own', false)
            ->where('person.id', $this->employee->id)
            ->where('scope', 'full')
            ->where('totals.week', 60)
            ->where('can.review', true)
            ->where('can.submit', false)
            ->has('people', 1)
            ->where('people.0.id', $this->employee->id));

    $outsider = User::factory()->employee()->inDepartment(Department::factory()->create(['name' => 'Marketing']))->create();
    $this->actingAs($head)->get("/horas?persona={$outsider->id}")->assertForbidden();
});

it('un gestor ve la hoja de los miembros de sus proyectos, solo con las entradas de esos proyectos', function () {
    $manager = User::factory()->employee()->create();
    $this->web->addMember($manager, isManager: true);
    ($this->log)($this->home, '2026-09-22', 60);
    ($this->log)($this->api, '2026-09-22', 240);

    $this->actingAs($manager)
        ->get("/horas?persona={$this->employee->id}&semana=2026-W39")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'managed_projects')
            ->has('rows', 1)
            ->where('rows.0.task.title', 'Home')
            ->where('totals.week', 60));

    $stranger = User::factory()->employee()->create();
    $this->actingAs($manager)->get("/horas?persona={$stranger->id}")->assertForbidden();
});

it('un gestor ve el estado de la semana de un miembro, pero no el comentario de quien la devolvió (D-021)', function () {
    $manager = User::factory()->employee()->create();
    $this->web->addMember($manager, isManager: true);
    $head = User::factory()->departmentManager()->inDepartment($this->department)->create(['name' => 'Lucía']);
    $this->department->managers()->attach($head);
    ($this->log)($this->home, '2026-09-22', 60);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Returned)->create([
        'reviewed_by' => $head->id,
        'reviewed_at' => now(),
        'review_comment' => 'Sobran horas en BETA-APP',
    ]);

    $this->actingAs($manager)
        ->get("/horas?persona={$this->employee->id}&semana=2026-W39")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'managed_projects')
            ->where('period.status', 'returned')
            ->where('period.review_comment', null));

    // Quien ve la hoja completa (su responsable y la propia persona) sí lo ve.
    foreach ([$head, $this->employee] as $viewer) {
        $this->actingAs($viewer)
            ->get("/horas?persona={$this->employee->id}&semana=2026-W39")
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'full')
                ->where('period.review_comment', 'Sobran horas en BETA-APP')
                ->where('period.reviewer.name', 'Lucía'));
    }
});

it('el admin ve la hoja de cualquiera; un cliente nunca entra', function () {
    $admin = User::factory()->admin()->create();
    ($this->log)($this->api, '2026-09-24', 30);

    $this->actingAs($admin)
        ->get("/horas?persona={$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('totals.week', 30)->where('can.review', false));

    $this->actingAs(userWithRole('client'))->get('/horas')->assertRedirect(route('portal.home'));
});

it('carga la hoja con muchas filas y entradas sin consultas perezosas (N+1)', function () {
    $tasks = Task::factory()->count(6)->create(['project_id' => $this->web->id]);
    foreach ($tasks as $task) {
        foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $date) {
            ($this->log)($task, $date, 20);
        }
    }
    TimeEntry::query()->where('date', '2026-09-21')->update(['status' => TimeEntryStatus::Draft->value]);

    $this->actingAs($this->employee)
        ->get('/horas?semana=2026-W39')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 6)->where('totals.week', 360));
});

it('marca los festivos y las ausencias de cada día para la vista por días; el tipo, solo a quien puede verlo (D-321, D-088)', function () {
    Holiday::factory()->create(['date' => '2026-09-24', 'name' => 'La Mercè']);
    Absence::factory()->for($this->employee)->approved()->between('2026-09-22', '2026-09-22')->create(['type' => AbsenceType::Sick]);
    Absence::factory()->for($this->employee)->approved()->between('2026-09-25', '2026-09-25')->partial(120)->create();

    $this->actingAs($this->employee)
        ->get('/horas?semana=2026-W39')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('day_notes', 7)
            ->where('day_notes.2026-09-21', ['holiday' => null, 'absence' => null])
            ->where('day_notes.2026-09-22.absence', ['type' => 'sick', 'partial' => false])
            ->where('day_notes.2026-09-24.holiday', 'La Mercè')
            ->where('day_notes.2026-09-25.absence', ['type' => 'vacation', 'partial' => true]));

    $manager = User::factory()->employee()->create();
    $this->web->addMember($manager, isManager: true);

    $this->actingAs($manager)
        ->get("/horas?persona={$this->employee->id}&semana=2026-W39")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('day_notes.2026-09-22.absence', ['type' => null, 'partial' => false])
            ->where('day_notes.2026-09-24.holiday', 'La Mercè'));
});
