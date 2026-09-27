<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
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

/*
| Métricas del SPEC §10 con un escenario pequeño CALCULADO A MANO (aceptación de la Fase 2).
| Semana del 21 al 27/09/2026. Diseño: Ana (8 h/día, coste 20 €/h, tarifa 50 €/h) y Luis
| (4 h/día, coste 30 €/h). Proyectos:
|  - «Por horas» (cliente a 60 €/h): Ana 300 min aprobados con instantánea 55 €/h y coste 20 €/h,
|    y 120 min en borrador → 275 + 120 = 395,00 €. Tarea estimada en 300 min, completada el 23.
|  - «Bolsa» 600 min, precio 1000 €, tarifa 70 €/h: Luis 500 + 200 min → 100 min de exceso →
|    1000 × 600/600 + 100 × 70/60 = 1116,67 €.
|  - «Interno»: Luis 60 min no facturables → 0 €.
|  - «Precio cerrado» 3000 €, presupuesto 1200 min: Ana 240 min esta semana (+60 en agosto) →
|    3000 × 240/1200 = 600,00 €.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id, 'hourly_cost' => '20.00', 'default_hourly_rate' => '50.00']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id, 'hourly_cost' => '30.00']);
    WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
    WorkSchedule::factory()->for($this->luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
    $this->admin = User::factory()->admin()->create();
    $this->head = User::factory()->departmentManager()->create(['name' => 'Raúl']);
    $this->design->managers()->attach($this->head);

    $client = Client::factory()->create(['default_hourly_rate' => '60.00']);
    $this->tm = Project::factory()->create(['client_id' => $client->id, 'billing_type' => 'time_and_materials', 'hourly_rate' => null, 'name' => 'Por horas']);
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

    $internal = Project::factory()->internal()->create(['name' => 'Interno']);
    $internalTask = Task::factory()->create(['project_id' => $internal->id, 'is_billable' => false]);
    TimeEntry::factory()->forTask($internalTask)->on('2026-09-25')->minutes(60)->create(['user_id' => $this->luis->id, 'is_billable' => false]);

    $this->fixed = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '3000.00', 'budget_minutes' => 1200, 'name' => 'Precio cerrado']);
    $fixedTask = Task::factory()->create(['project_id' => $this->fixed->id, 'estimated_minutes' => 600]);
    TimeEntry::factory()->forTask($fixedTask)->on('2026-09-24')->minutes(240)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($fixedTask)->on('2026-08-10')->minutes(60)->create(['user_id' => $this->ana->id]);

    $this->week = fn (array $query = []) => ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]);
    $this->metrics = app(Metrics::class);
});

it('calcula todas las métricas del SPEC §10 como a mano (admin, departamento Diseño)', function () {
    $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));
    $s = $this->metrics->summary($scope);

    expect($s['capacity_minutes'])->toBe(3600)
        ->and($s['logged_minutes'])->toBe(1420)
        ->and($s['billable_minutes'])->toBe(1360)
        ->and($s['overage_minutes'])->toBe(100)
        // Dentro de bolsa (D-078): solo las entradas con bolsa sin su exceso: 700 − 100.
        ->and($s['in_bank_minutes'])->toBe(600)
        ->and($s['occupancy'])->toBe(0.3944)
        ->and($s['billability'])->toBe(0.9577)
        ->and($s['billable_productivity'])->toBe(0.3778)
        ->and($s['income'])->toBe('2111.67')
        ->and($s['cost'])->toBe('600.00')
        ->and($s['margin'])->toBe('1511.67')
        ->and($s['margin_pct'])->toBe(0.7159)
        ->and($s['estimation'])->toBe([
            'tasks' => 1,
            'estimated_minutes' => 300,
            'actual_minutes' => 420,
            'accuracy' => 0.7143,
            'deviation' => 0.4,
        ]);
});

it('desglosa por proyecto con ingreso y coste de cada uno', function () {
    $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));
    $rows = collect($this->metrics->breakdown($scope, Dimension::Project))->keyBy('name');

    expect($rows[$this->bank->project->code.' · '.$this->bank->project->name])->toMatchArray([
        'logged_minutes' => 700, 'overage_minutes' => 100, 'in_bank_minutes' => 600, 'income' => '1116.67', 'cost' => '350.00',
    ])
        ->and($rows[$this->tm->code.' · Por horas'])->toMatchArray(['logged_minutes' => 420, 'income' => '395.00', 'cost' => '140.00'])
        ->and($rows[$this->fixed->code.' · Precio cerrado'])->toMatchArray(['logged_minutes' => 240, 'income' => '600.00', 'cost' => '80.00'])
        ->and(collect($this->metrics->breakdown($scope, Dimension::Project))->first()['logged_minutes'])->toBe(700);
});

it('da la serie diaria con capacidad e ingreso, sin huecos', function () {
    $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));
    $series = collect($this->metrics->series($scope, Dimension::Day))->keyBy('bucket');

    expect($series)->toHaveCount(7)
        ->and($series['2026-09-22'])->toMatchArray(['logged_minutes' => 800, 'billable_minutes' => 800, 'capacity_minutes' => 720, 'income' => '1108.33'])
        ->and($series['2026-09-24']['logged_minutes'])->toBe(440)
        ->and($series['2026-09-26'])->toMatchArray(['logged_minutes' => 0, 'capacity_minutes' => 0, 'income' => '0.00']);
});

it('agrupa por semana y mes con los lunes y los días 1', function () {
    $scope = new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'trimestre', 'fecha' => '2026-09-01', 'departamento' => [$this->design->id]]));

    $months = collect($this->metrics->series($scope, Dimension::Month))->keyBy('bucket');
    expect($months->keys()->all())->toBe(['2026-07-01', '2026-08-01', '2026-09-01'])
        ->and($months['2026-08-01']['logged_minutes'])->toBe(60)
        ->and($months['2026-09-01']['logged_minutes'])->toBe(1420);

    $weeks = collect($this->metrics->series($scope, Dimension::Week))->keyBy('bucket');
    expect($weeks['2026-09-21']['logged_minutes'])->toBe(1420)
        ->and($weeks['2026-08-10']['logged_minutes'])->toBe(60);
});

it('una empleada solo se ve a sí misma y sin datos económicos (D-044)', function () {
    $s = $this->metrics->summary(new ReportScope($this->ana, ($this->week)()));

    expect($s['logged_minutes'])->toBe(660)
        ->and($s['capacity_minutes'])->toBe(2400)
        ->and($s['occupancy'])->toBe(0.275)
        ->and($s['income'])->toBeNull()
        ->and($s['cost'])->toBeNull()
        ->and($s['margin'])->toBeNull();

    // Aunque filtre por otra persona, no ve sus horas.
    expect($this->metrics->summary(new ReportScope($this->ana, ($this->week)(['persona' => [$this->luis->id]])))['logged_minutes'])->toBe(0);
});

it('un responsable ve a su equipo y su propia capacidad, sin datos económicos', function () {
    $s = $this->metrics->summary(new ReportScope($this->head, ($this->week)()));

    // Raúl no tiene horario: jornada por defecto desde su alta (hoy, viernes): 480.
    expect($s['logged_minutes'])->toBe(1420)
        ->and($s['capacity_minutes'])->toBe(3600 + 480)
        ->and($s['income'])->toBeNull();
});

it('un gestor sin departamento solo ve su capacidad y las horas de sus proyectos', function () {
    $manager = User::factory()->employee()->create();
    $this->bank->project->addMember($manager, isManager: true);

    $s = $this->metrics->summary(new ReportScope($manager, ($this->week)()));

    expect($s['logged_minutes'])->toBe(700)
        ->and($s['capacity_minutes'])->toBe(480);
});

it('filtra por facturable, cliente, bolsa y tipo', function () {
    $admin = fn (array $q) => $this->metrics->summary(new ReportScope($this->admin, ($this->week)($q)))['logged_minutes'];

    expect($admin(['facturable' => 'no']))->toBe(60)
        ->and($admin(['facturable' => 'si']))->toBe(1360)
        ->and($admin(['cliente' => [$this->tm->client_id]]))->toBe(420)
        ->and($admin(['bolsa' => [$this->bank->id]]))->toBe(700)
        ->and($admin(['proyecto' => [$this->fixed->id, $this->tm->id]]))->toBe(660);
});

it('la tabla dinámica cruza persona × proyecto con subtotales', function () {
    $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));
    $pivot = app(PivotReport::class)->run($scope, Dimension::Person, Dimension::Project);

    expect($pivot['total'])->toBe(1420)
        ->and($pivot['row_totals'][(string) $this->ana->id])->toBe(660)
        ->and($pivot['row_totals'][(string) $this->luis->id])->toBe(760)
        ->and($pivot['cells'][(string) $this->luis->id][(string) $this->bank->project_id])->toBe(700)
        ->and(collect($pivot['rows'])->pluck('name')->all())->toBe(['Luis', 'Ana'])
        ->and($pivot['truncated'])->toBeFalse();

    $billable = app(PivotReport::class)->run($scope, Dimension::Person, Dimension::Week, 'billable');
    expect($billable['total'])->toBe(1360)->and($billable['columns'][0]['key'])->toBe('2026-09-21');
});

it('la caché se invalida al escribir una entrada', function () {
    $scope = new ReportScope($this->admin, ($this->week)());
    $cache = app(ReportCache::class);
    $read = fn () => $cache->remember($scope, 'summary', fn () => $this->metrics->summary($scope)['logged_minutes']);

    $before = $read();
    TimeEntry::query()->whereKey(TimeEntry::query()->min('id'))->update(['minutes' => 1]);
    expect($read())->toBe($before);

    TimeEntry::factory()->forTask($this->tmTask)->on('2026-09-24')->minutes(30)->create(['user_id' => $this->ana->id]);
    expect($read())->not->toBe($before);
});
