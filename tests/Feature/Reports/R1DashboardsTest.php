<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/*
| R1 · Dashboards de dirección, departamento y persona con el escenario CALCULADO A MANO de
| MetricsTest (semana del 21 al 27/09/2026, «hoy» es el viernes 25):
|  - Diseño: Ana (8 h/día, coste 20 €/h) y Luis (4 h/día, coste 30 €/h); responsable Raúl.
|  - «Por horas» (cliente a 60 €/h): Ana 300 min aprobados a 55 €/h + 120 en borrador = 395,00 €;
|    coste 100 + 40 = 140 €. Tarea estimada en 300 min, completada el 23 (real: 420).
|  - «Bolsa» 600 min, 1000 €, 70 €/h: Luis 500 + 200 → 100 de exceso → 1116,67 €; coste 350 €.
|  - «Interno»: Luis 60 min no facturables → 0 €; coste 30 €.
|  - «Precio cerrado» 3000 €, presupuesto 1200: Ana 240 esta semana → 600,00 €; coste 80 €.
|  Diseño en la semana: capacidad 2400 + 1200 = 3600; imputadas 1420; facturables 1360;
|  ingreso 2111,67 €; coste 600 €; margen 1511,67 € (71,59 %).
| Además, Marta (Marketing, fuera del equipo de Raúl) imputa 90 min a «Por horas» el 23.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    // Ana y Luis están de alta desde enero (los días sin imputar nunca cuentan antes del alta).
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id, 'hourly_cost' => '20.00', 'default_hourly_rate' => '50.00', 'created_at' => '2026-01-01 08:00']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id, 'hourly_cost' => '30.00', 'created_at' => '2026-01-01 08:00']);
    $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $this->marketing->id, 'hourly_cost' => '25.00']);
    WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
    WorkSchedule::factory()->for($this->luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
    WorkSchedule::factory()->for($this->marta)->create(['valid_from' => '2026-01-01']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->head = User::factory()->departmentManager()->create(['name' => 'Raúl']);
    $this->design->managers()->attach($this->head);

    $this->tmClient = Client::factory()->create(['name' => 'Cliente por horas', 'default_hourly_rate' => '60.00']);
    $this->tm = Project::factory()->create(['client_id' => $this->tmClient->id, 'billing_type' => 'time_and_materials', 'hourly_rate' => null, 'name' => 'Por horas']);
    $this->tmTask = Task::factory()->create(['project_id' => $this->tm->id, 'estimated_minutes' => 300, 'assignee_user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->tmTask)->on('2026-09-22')->minutes(300)->create([
        'user_id' => $this->ana->id, 'status' => TimeEntryStatus::Approved,
        'hourly_rate_snapshot' => '55.00', 'hourly_cost_snapshot' => '20.00',
    ]);
    TimeEntry::factory()->forTask($this->tmTask)->on('2026-09-23')->minutes(120)->create(['user_id' => $this->ana->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'Europe/Madrid'));
    $this->tmTask->update(['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));

    $this->bank = HourBank::factory()->create(['total_minutes' => 600, 'price_amount' => '1000.00', 'hourly_rate' => '70.00']);
    $bankTask = Task::factory()->inBank($this->bank)->create();
    TimeEntry::factory()->forTask($bankTask)->on('2026-09-22')->minutes(500)->create(['user_id' => $this->luis->id]);
    TimeEntry::factory()->forTask($bankTask)->on('2026-09-24')->minutes(200)->create(['user_id' => $this->luis->id]);

    $this->internal = Project::factory()->internal()->create(['name' => 'Interno']);
    $internalTask = Task::factory()->create(['project_id' => $this->internal->id, 'is_billable' => false]);
    TimeEntry::factory()->forTask($internalTask)->on('2026-09-25')->minutes(60)->create(['user_id' => $this->luis->id, 'is_billable' => false]);

    $this->fixed = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '3000.00', 'budget_minutes' => 1200, 'name' => 'Precio cerrado']);
    $fixedTask = Task::factory()->create(['project_id' => $this->fixed->id, 'estimated_minutes' => 600]);
    TimeEntry::factory()->forTask($fixedTask)->on('2026-09-24')->minutes(240)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($fixedTask)->on('2026-08-10')->minutes(60)->create(['user_id' => $this->ana->id]);

    $martaTask = Task::factory()->create(['project_id' => $this->tm->id, 'assignee_user_id' => $this->marta->id]);
    TimeEntry::factory()->forTask($martaTask)->on('2026-09-23')->minutes(90)->create(['user_id' => $this->marta->id]);

    $this->week = fn (array $query = []): string => '?'.http_build_query(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]);
    $this->projectName = fn (Project $project): string => $project->code.' · '.$project->name;
});

describe('dirección', function () {
    it('da las cifras del SPEC §10 como a mano (admin, Diseño)', function () {
        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)(['departamento' => [$this->design->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/direction')
                ->where('limited_to', null)
                ->where('summary.capacity_minutes', 3600)
                ->where('summary.logged_minutes', 1420)
                ->where('summary.billable_minutes', 1360)
                ->where('summary.occupancy', 0.3944)
                ->where('summary.billability', 0.9577)
                ->where('summary.billable_productivity', 0.3778)
                ->where('summary.income', '2111.67')
                ->where('summary.cost', '600.00')
                ->where('summary.margin', '1511.67')
                ->where('summary.margin_pct', 0.7159)
                ->where('summary.estimation.accuracy', 0.7143)
                ->where('summary.estimation.deviation', 0.4)
                ->where('comparison', null)
                ->has('departments', 1)
                ->where('departments.0.name', 'Diseño')
                ->where('departments.0.logged_minutes', 1420)
                ->where('departments.0.margin', '1511.67')
                ->where('series.bucket', 'semana')
                ->has('series.points', 1)
                ->where('series.points.0', ['bucket' => '2026-09-21', 'logged_minutes' => 1420, 'billable_minutes' => 1360, 'capacity_minutes' => 3600, 'income' => '2111.67'])
                ->where('clients.others', null)
                ->has('clients.rows', 4)
                ->where('clients.rows.0.key', (string) $this->bank->project->client_id)
                ->where('clients.rows.0.income', '1116.67')
                ->where('clients.rows.1.name', 'Cliente por horas')
                ->where('clients.rows.1.logged_minutes', 420)
                ->where('clients.rows.1.income', '395.00')
                ->where('clients.rows.1.margin', '255.00')
                ->where('clients.rows.3.key', null)
                ->where('clients.rows.3.name', 'Interno (sin cliente)')
                ->where('projects.rows.0.name', ($this->projectName)($this->bank->project))
                ->where('projects.rows.0.margin', '766.67')
                ->where('projects.rows.2.name', ($this->projectName)($this->fixed))
                ->where('projects.rows.2.income', '600.00')
                ->where('projects.rows.3.margin', '-30.00'));
    });

    it('sin filtros, el admin ve toda la agencia (también Marketing)', function () {
        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.logged_minutes', 1510)
                ->has('departments', 2)
                ->where('departments.1.name', 'Marketing')
                ->where('departments.1.logged_minutes', 90));
    });

    it('limita a un responsable a sus departamentos y le oculta los datos económicos (D-044)', function () {
        $assert = fn (Assert $page) => $page
            ->where('filters.query.departamento', [$this->design->id])
            ->where('limited_to', ['Diseño'])
            ->where('summary.logged_minutes', 1420)
            ->where('summary.capacity_minutes', 3600)
            ->where('summary.income', null)
            ->where('summary.margin', null)
            ->where('filters.can_see_financials', false)
            ->has('departments', 1)
            ->where('clients.rows.0.income', null)
            ->where('projects.rows.0.margin', null)
            ->where('series.points.0.income', null);

        $this->actingAs($this->head)->get('/informes/direccion'.($this->week)())->assertOk()->assertInertia($assert);

        // No puede ampliar el alcance con el filtro: se queda en los suyos.
        $this->actingAs($this->head)
            ->get('/informes/direccion'.($this->week)(['departamento' => [$this->marketing->id]]))
            ->assertOk()
            ->assertInertia($assert);
    });

    it('compara con el periodo anterior con comparar=1', function () {
        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)(['departamento' => [$this->design->id], 'comparar' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.comparison', ['from' => '2026-09-14', 'to' => '2026-09-20'])
                ->where('comparison.logged_minutes', 0)
                ->where('comparison.capacity_minutes', 3600)
                ->where('comparison.income', '0.00'));
    });

    it('agrupa la evolución por meses si el periodo es mayor que un trimestre', function () {
        $this->actingAs($this->admin)
            ->get('/informes/direccion?'.http_build_query(['periodo' => 'anio', 'fecha' => '2026-01-01', 'departamento' => [$this->design->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('series.bucket', 'mes')
                ->has('series.points', 12)
                ->where('series.points.7.bucket', '2026-08-01')
                ->where('series.points.7.logged_minutes', 60)
                ->where('series.points.8.logged_minutes', 1420));

        $this->actingAs($this->admin)
            ->get('/informes/direccion?'.http_build_query(['periodo' => 'trimestre', 'fecha' => '2026-07-01']))
            ->assertInertia(fn (Assert $page) => $page->where('series.bucket', 'semana'));
    });

    it('lista las bolsas en riesgo desde el primer umbral: todas para el admin y las de su equipo o departamento para un responsable', function () {
        $bank = function (int $total, User $user, int $minutes, ?Department $department = null): HourBank {
            $bank = HourBank::factory()->create(['total_minutes' => $total, 'department_id' => $department?->id]);
            TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->on('2026-09-21')->minutes($minutes)->create(['user_id' => $user->id]);

            return $bank->fresh() ?? $bank;
        };

        $near = $bank(600, $this->luis, 480);                              // 80 %: en riesgo
        $bank(600, $this->luis, 300);                                      // 50 %: no
        $closed = $bank(600, $this->luis, 600);                            // cerrada: no
        $closed->update(['status' => HourBankStatus::Closed]);
        $archived = $bank(600, $this->luis, 600);                          // proyecto archivado: no
        $archived->project->update(['status' => 'archived']);
        $marketingBank = $bank(600, $this->marta, 500, $this->marketing);  // 83 %: solo admin
        $designBank = $bank(60, $this->marta, 54, $this->design);          // 90 %, del departamento de Raúl

        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('at_risk.threshold', 75)
                ->where('at_risk.count', 4)
                ->where('at_risk.banks.0.id', $this->bank->id)
                ->where('at_risk.banks.0.status', 'exhausted')
                ->where('at_risk.banks.0.consumed_minutes', 700)
                ->where('at_risk.banks.0.overage_minutes', 100)
                ->where('at_risk.banks.0.ratio', 1)
                ->where('at_risk.banks.1.id', $designBank->id)
                ->where('at_risk.banks.2.id', $marketingBank->id)
                ->where('at_risk.banks.3.id', $near->id)
                ->where('at_risk.banks.3.ratio', 0.8)
                ->where('at_risk.banks.3.project.code', $near->project->code));

        $this->actingAs($this->head)
            ->get('/informes/direccion'.($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('at_risk.count', 3)
                ->where('at_risk.banks', fn ($banks) => collect($banks)->pluck('id')->all() === [$this->bank->id, $designBank->id, $near->id]));
    });

    it('cuenta las tareas vencidas del alcance (abiertas, antes de hoy y de proyectos no archivados)', function () {
        $task = fn (array $attributes) => Task::factory()->create(['project_id' => $this->tm->id, ...$attributes]);
        $ana = $task(['title' => 'De Ana', 'assignee_user_id' => $this->ana->id, 'due_date' => '2026-09-20']);
        $task(['title' => 'De Marta', 'assignee_user_id' => $this->marta->id, 'due_date' => '2026-09-10']);
        $task(['title' => 'Sin asignar', 'assignee_user_id' => null, 'due_date' => '2026-09-01']);
        $task(['title' => 'Vence hoy', 'assignee_user_id' => $this->ana->id, 'due_date' => '2026-09-25']);
        Task::factory()->completed()->create(['project_id' => $this->tm->id, 'assignee_user_id' => $this->ana->id, 'due_date' => '2026-09-01']);
        $archived = Project::factory()->archived()->create();
        Task::factory()->create(['project_id' => $archived->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-02']);

        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('overdue.count', 3)
                ->where('overdue.tasks.0.title', 'Sin asignar')
                ->where('overdue.tasks.0.assignee', null)
                ->where('overdue.tasks.1.title', 'De Marta')
                ->where('overdue.tasks.2.id', $ana->id)
                ->where('overdue.tasks.2.assignee', 'Ana')
                ->where('overdue.tasks.2.due_date', '2026-09-20')
                ->where('overdue.tasks.2.days_overdue', 5)
                ->where('overdue.tasks.2.project.code', $this->tm->code));

        $this->actingAs($this->head)
            ->get('/informes/direccion'.($this->week)())
            ->assertInertia(fn (Assert $page) => $page->where('overdue.count', 1)->where('overdue.tasks.0.id', $ana->id));

        $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)(['departamento' => [$this->design->id]]))
            ->assertInertia(fn (Assert $page) => $page->where('overdue.count', 1));
    });
});

describe('departamento', function () {
    it('da la ocupación, la facturabilidad y el dinero de cada miembro como a mano', function () {
        $this->actingAs($this->admin)
            ->get("/informes/departamentos/{$this->design->id}".($this->week)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/department')
                ->where('department.name', 'Diseño')
                ->missing('filters.query.departamento')
                ->where('summary.logged_minutes', 1420)
                ->where('summary.capacity_minutes', 3600)
                ->where('summary.income', '2111.67')
                ->where('occupancy_thresholds', ['low' => 70, 'high' => 110])
                ->has('members', 2)
                ->where('members.0', [
                    'id' => $this->luis->id, 'name' => 'Luis', 'is_active' => true,
                    'capacity_minutes' => 1200, 'logged_minutes' => 760, 'billable_minutes' => 700,
                    'occupancy' => 0.6333, 'billability' => 0.9211, 'billable_productivity' => 0.5833,
                    'income' => '1116.67', 'cost' => '380.00', 'margin' => '736.67',
                ])
                ->where('members.1', [
                    'id' => $this->ana->id, 'name' => 'Ana', 'is_active' => true,
                    'capacity_minutes' => 2400, 'logged_minutes' => 660, 'billable_minutes' => 660,
                    'occupancy' => 0.275, 'billability' => 1, 'billable_productivity' => 0.275,
                    'income' => '995.00', 'cost' => '220.00', 'margin' => '775.00',
                ])
                ->where('clients.rows.1.name', 'Cliente por horas')
                ->where('clients.rows.1.logged_minutes', 420));
    });

    it('incluye a los miembros sin horas y oculta el dinero a un responsable', function () {
        $new = User::factory()->employee()->create(['name' => 'Zoe', 'department_id' => $this->design->id]);
        WorkSchedule::factory()->for($new)->create(['valid_from' => '2026-01-01']);

        $this->actingAs($this->head)
            ->get("/informes/departamentos/{$this->design->id}".($this->week)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 3)
                ->where('members.2.name', 'Zoe')
                ->where('members.2.logged_minutes', 0)
                ->where('members.2.capacity_minutes', 2400)
                ->where('members.2.occupancy', 0)
                ->where('members.2.billability', null)
                ->where('members.0.income', null)
                ->where('members.0.margin', null)
                ->where('summary.income', null));
    });
});

describe('persona', function () {
    it('da a Ana sus cifras, su calendario y sus días sin imputar, sin datos económicos', function () {
        $this->actingAs($this->ana)
            ->get("/informes/personas/{$this->ana->id}".($this->week)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/person')
                ->where('is_self', true)
                ->where('person.department', ['id' => $this->design->id, 'name' => 'Diseño', 'can_view' => false])
                ->missing('filters.query.persona')
                ->where('summary.capacity_minutes', 2400)
                ->where('summary.logged_minutes', 660)
                ->where('summary.billable_minutes', 660)
                ->where('summary.occupancy', 0.275)
                ->where('summary.estimation.tasks', 1)
                ->where('summary.estimation.accuracy', 0.7143)
                ->where('summary.income', null)
                ->where('days', [
                    ['date' => '2026-09-21', 'minutes' => 0], ['date' => '2026-09-22', 'minutes' => 300],
                    ['date' => '2026-09-23', 'minutes' => 120], ['date' => '2026-09-24', 'minutes' => 240],
                    ['date' => '2026-09-25', 'minutes' => 0], ['date' => '2026-09-26', 'minutes' => 0],
                    ['date' => '2026-09-27', 'minutes' => 0],
                ])
                ->where('unlogged', [['date' => '2026-09-21', 'capacity_minutes' => 480, 'week' => '2026-W39']])
                ->where('clients.rows.0.name', 'Cliente por horas')
                ->where('clients.rows.0.logged_minutes', 420)
                ->where('clients.rows.0.income', null)
                ->has('projects.rows', 2)
                ->has('types.rows', 1));
    });

    it('los días sin imputar no dependen de los filtros de la URL', function () {
        $this->actingAs($this->ana)
            ->get("/informes/personas/{$this->ana->id}".($this->week)(['cliente' => [$this->tmClient->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.logged_minutes', 420)
                ->where('days.3', ['date' => '2026-09-24', 'minutes' => 0])
                ->where('unlogged', [['date' => '2026-09-21', 'capacity_minutes' => 480, 'week' => '2026-W39']]));

        // Luis: el lunes y el miércoles sin horas; el viernes (hoy) aún no cuenta.
        $this->actingAs($this->luis)
            ->get("/informes/personas/{$this->luis->id}".($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('unlogged.0.date', '2026-09-21')
                ->where('unlogged.1.date', '2026-09-23')
                ->has('unlogged', 2));
    });

    it('nunca cuenta días sin imputar antes de que cuente su capacidad', function () {
        $new = User::factory()->employee()->create(['department_id' => $this->design->id]);

        $this->actingAs($new)
            ->get("/informes/personas/{$new->id}".($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.capacity_minutes', 480)
                ->where('unlogged', []));
    });

    it('nunca cuenta días sin imputar antes del alta, aunque su horario empiece antes', function () {
        // De alta el miércoles 23 con un horario desde enero: la capacidad cuenta toda la semana
        // (5 × 480), pero solo se le reclaman el miércoles y el jueves (el viernes es hoy).
        $new = User::factory()->employee()->create(['department_id' => $this->design->id, 'created_at' => '2026-09-23 10:00']);
        WorkSchedule::factory()->for($new)->create(['valid_from' => '2026-01-01']);

        $this->actingAs($new)
            ->get("/informes/personas/{$new->id}".($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.capacity_minutes', 2400)
                ->where('unlogged', [
                    ['date' => '2026-09-23', 'capacity_minutes' => 480, 'week' => '2026-W39'],
                    ['date' => '2026-09-24', 'capacity_minutes' => 480, 'week' => '2026-W39'],
                ]));
    });

    it('el admin ve el dinero de la persona y su responsable no', function () {
        $this->actingAs($this->admin)
            ->get("/informes/personas/{$this->ana->id}".($this->week)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_self', false)
                ->where('person.department.can_view', true)
                ->where('summary.income', '995.00')
                ->where('summary.cost', '220.00')
                ->where('summary.margin', '775.00')
                ->where('clients.rows.0.income', '395.00'));

        $this->actingAs($this->head)
            ->get("/informes/personas/{$this->ana->id}".($this->week)())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.logged_minutes', 660)
                ->where('summary.income', null)
                ->where('person.department.can_view', true));
    });
});

describe('índice', function () {
    it('enseña a cada uno los dashboards a los que tiene acceso (D-044)', function () {
        $this->actingAs($this->admin)->get('/informes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('direction', true)
            ->has('departments', 2)
            ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($this->tmClient->id))
            ->where('projects', fn ($projects) => collect($projects)->pluck('id')->contains($this->fixed->id))
            ->where('people', fn ($people) => collect($people)->pluck('name')->contains('Marta')));

        $this->actingAs($this->head)->get('/informes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('direction', true)
            ->where('departments', [['id' => $this->design->id, 'name' => 'Diseño', 'color' => $this->design->color]])
            ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($this->tmClient->id))
            ->where('people', fn ($people) => collect($people)->pluck('name')->sort()->values()->all() === ['Ana', 'Luis', 'Raúl']));

        $this->actingAs($this->ana)->get('/informes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('me', ['id' => $this->ana->id, 'name' => 'Ana'])
            ->where('direction', false)
            ->where('departments', [])
            ->where('clients', null)
            ->where('projects', null)
            ->where('people', [['id' => $this->ana->id, 'name' => 'Ana', 'department' => 'Diseño', 'is_active' => true]]));
    });

    it('a un gestor le enseña los clientes y proyectos que gestiona', function () {
        $manager = User::factory()->employee()->create();
        $this->fixed->addMember($manager, isManager: true);

        $this->actingAs($manager)->get('/informes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('direction', false)
            ->where('projects', fn ($projects) => collect($projects)->pluck('id')->all() === [$this->fixed->id])
            ->where('clients', fn ($clients) => collect($clients)->pluck('id')->all() === [$this->fixed->client_id])
            ->has('people', 1));
    });
});

describe('Inicio: mis indicadores', function () {
    it('da a Ana los suyos del mes en curso', function () {
        // Septiembre de 2026: 22 días laborables × 8 h = 10560 min.
        $this->actingAs($this->ana)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('home', false)
            ->where('indicators.from', '2026-09-01')
            ->where('indicators.to', '2026-09-30')
            ->where('indicators.capacity_minutes', 10560)
            ->where('indicators.logged_minutes', 660)
            ->where('indicators.billable_minutes', 660)
            ->where('indicators.occupancy', 0.0625)
            ->where('indicators.billability', 1)
            ->where('indicators.estimation.accuracy', 0.7143)
            ->where('indicators.clients', [
                ['key' => (string) $this->tmClient->id, 'name' => 'Cliente por horas', 'logged_minutes' => 420],
                ['key' => (string) $this->fixed->client_id, 'name' => $this->fixed->client->name, 'logged_minutes' => 240],
            ])
            ->where('indicators.projects.0.name', ($this->projectName)($this->tm))
            ->missing('indicators.income'));
    });

    it('un responsable o un admin solo ve los suyos, no los de su equipo', function () {
        foreach ([$this->head, $this->admin] as $user) {
            $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('indicators.logged_minutes', 0)
                ->where('indicators.clients', [])
                ->where('indicators.projects', [])
                ->where('indicators.estimation.tasks', 0));
        }
    });
});

describe('exportaciones', function () {
    beforeEach(function () {
        $this->xlsx = function (string $content): array {
            $path = tempnam(sys_get_temp_dir(), 'r1').'.xlsx';
            file_put_contents($path, $content);
            $reader = new XlsxReader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $reader->close();
            unlink($path);

            return $rows;
        };
        $this->csv = fn (string $content): array => array_map(
            fn (string $line): array => str_getcsv($line, ';', '"', ''),
            array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $content)), fn (string $line): bool => trim($line) !== '')),
        );
    });

    it('exporta a XLSX el reparto por proyecto de dirección con ingreso, coste y margen (admin)', function () {
        $response = $this->actingAs($this->admin)
            ->get('/informes/direccion'.($this->week)(['departamento' => [$this->design->id], 'tabla' => 'proyectos', 'formato' => 'xlsx']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        expect($response->headers->get('Content-Disposition'))->toContain('informe-de-direccion-proyectos-');

        $rows = ($this->xlsx)($response->streamedContent());

        expect($rows)->toHaveCount(5)
            ->and($rows[0])->toBe(['Proyecto', 'Horas imputadas', 'Horas facturables', '% del total', 'Facturabilidad (%)', 'Ingreso estimado (€)', 'Coste (€)', 'Rentabilidad (€)'])
            // Las celdas son números (el lector devuelve los enteros como int): se comparan por valor.
            ->and($rows[1])->toEqual([($this->projectName)($this->bank->project), 11.67, 11.67, 49.3, 100, 1116.67, 350, 766.67])
            ->and($rows[2])->toEqual([($this->projectName)($this->tm), 7, 7, 29.6, 100, 395, 140, 255])
            ->and($rows[4])->toEqual([($this->projectName)($this->internal), 1, 0, 4.2, 0, 0, 30, -30]);
    });

    it('exporta a CSV el reparto por cliente de un responsable sin datos económicos', function () {
        $response = $this->actingAs($this->head)
            ->get('/informes/direccion'.($this->week)(['formato' => 'csv', 'tabla' => 'clientes']))
            ->assertOk();

        $rows = ($this->csv)($response->streamedContent());

        expect($rows[0])->toBe(['Cliente', 'Horas imputadas', 'Horas facturables', '% del total', 'Facturabilidad (%)'])
            ->and($rows)->toHaveCount(5)
            ->and($rows[2])->toBe(['Cliente por horas', '7,00', '7,00', '29,60', '100,00'])
            ->and($rows[4])->toBe(['Interno (sin cliente)', '1,00', '0,00', '4,20', '0,00']);
    });

    it('exporta la tabla de miembros del departamento', function () {
        $admin = ($this->csv)($this->actingAs($this->admin)
            ->get("/informes/departamentos/{$this->design->id}".($this->week)(['formato' => 'csv']))
            ->assertOk()->streamedContent());

        expect($admin[0])->toBe(['Persona', 'Capacidad (h)', 'Horas imputadas', 'Horas facturables', 'Ocupación (%)', 'Facturabilidad (%)', 'Productividad facturable (%)', 'Ingreso estimado (€)', 'Coste (€)', 'Rentabilidad (€)'])
            ->and($admin[1])->toBe(['Luis', '20,00', '12,67', '11,67', '63,30', '92,10', '58,30', '1116,67', '380,00', '736,67'])
            ->and($admin[2])->toBe(['Ana', '40,00', '11,00', '11,00', '27,50', '100,00', '27,50', '995,00', '220,00', '775,00']);

        $head = ($this->csv)($this->actingAs($this->head)
            ->get("/informes/departamentos/{$this->design->id}".($this->week)(['formato' => 'csv']))
            ->streamedContent());

        expect($head[0])->toHaveCount(7)->and($head[1])->toHaveCount(7);
    });

    it('exporta el detalle diario de una persona (con el ingreso solo para quien puede verlo)', function () {
        $own = ($this->csv)($this->actingAs($this->ana)
            ->get("/informes/personas/{$this->ana->id}".($this->week)(['formato' => 'csv']))
            ->assertOk()->streamedContent());

        expect($own)->toHaveCount(8)
            ->and($own[0])->toBe(['Fecha', 'Día', 'Capacidad (h)', 'Horas imputadas', 'Horas facturables', 'Ocupación (%)'])
            ->and($own[1])->toBe(['21/09/2026', 'lunes', '8,00', '0,00', '0,00', '0,00'])
            ->and($own[2])->toBe(['22/09/2026', 'martes', '8,00', '5,00', '5,00', '62,50'])
            ->and($own[6])->toBe(['26/09/2026', 'sábado', '0,00', '0,00', '0,00', '']);

        $admin = ($this->csv)($this->actingAs($this->admin)
            ->get("/informes/personas/{$this->ana->id}".($this->week)(['formato' => 'csv']))
            ->streamedContent());

        expect($admin[0][6])->toBe('Ingreso estimado (€)')
            ->and($admin[2][6])->toBe('275,00');
    });

    it('un formato desconocido enseña la página', function () {
        $this->actingAs($this->admin)
            ->get('/informes/direccion?formato=pdf')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/direction'));
    });
});
