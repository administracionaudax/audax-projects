<?php

use App\Domain\Forecast\ForecastPeriod;
use App\Domain\Forecast\LoadCombiner;
use App\Models\Absence;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Lo que necesitan las pantallas de la previsión (D-300 a D-306): los colaboradores externos con
| asignaciones, festivos y ausencias por columna, los huecos y los previstos de debajo de la matriz,
| «Asignar a…», «Mi carga» en Inicio y en /carga, la barra lateral y la búsqueda.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    enableForecast();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->manager = userWithRole('department_manager', ['name' => 'Raúl', 'department_id' => $this->design->id]);
    $this->design->managers()->attach($this->manager->id);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
    $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $this->dev->id]);
    $this->project = Project::factory()->create(['owner_user_id' => $this->manager->id]);
    $this->combiner = app(LoadCombiner::class);
    $this->period = ForecastPeriod::make(CarbonImmutable::parse('2026-11-02'), 1, ForecastPeriod::WEEK);
    $this->person = fn (array $board, User $user): ?array => collect($board['people'])->firstWhere('id', $user->id);
});

describe('colaboradores externos (D-300)', function () {
    it('con asignaciones salen aparte, con la capacidad de su jornada, y no suman a su departamento', function () {
        $amparo = User::factory()->collaborator()->create(['name' => 'Amparo', 'department_id' => $this->design->id]);
        WorkSchedule::factory()->create(['user_id' => $amparo->id, 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
        Allocation::factory()->forProject($this->project)->forUser($amparo)->perDay(120)->between('2026-11-02', '2026-11-06')->create();

        $board = $this->combiner->board($this->period);
        $person = ($this->person)($board, $amparo);
        $design = collect($board['departments'])->firstWhere('id', $this->design->id);

        expect($person)->toMatchArray(['collaborator' => true, 'has_schedule' => true, 'weekly_minutes' => 1200])
            ->and($person['cells'][0])->toMatchArray(['capacity' => 1200, 'real' => 600])
            ->and($design['people'])->toBe(2)
            ->and($design['cells'][0]['capacity'])->toBe(2 * 5 * 480)
            ->and($design['cells'][0]['real'])->toBe(0)
            ->and($board['totals'][0]['real'])->toBe(0);
    });

    it('sin jornada no tienen capacidad (la celda dice «sin jornada»), y sin carga no salen', function () {
        $amparo = User::factory()->collaborator()->create(['name' => 'Amparo']);
        User::factory()->collaborator()->create(['name' => 'Sin nada']);
        Allocation::factory()->forProject($this->project)->forUser($amparo)->total(600)->between('2026-11-02', '2026-11-06')->create();

        $board = $this->combiner->board($this->period);
        $person = ($this->person)($board, $amparo);

        expect($person)->toMatchArray(['collaborator' => true, 'has_schedule' => false, 'weekly_minutes' => 0])
            ->and($person['cells'][0])->toMatchArray(['capacity' => 0, 'real' => 600])
            ->and(collect($board['people'])->pluck('name')->all())->toBe(['Ana', 'Marta', 'Raúl', 'Amparo']);
    });

    it('«Asignar a…» los ofrece con su ocupación aunque no tengan carga', function () {
        $amparo = User::factory()->collaborator()->create(['name' => 'Amparo']);
        Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(240)->between('2026-11-02', '2026-11-06')->create();

        $people = $this->actingAs($this->manager)
            ->getJson('/prevision/disponibilidad?desde=2026-11-02&hasta=2026-11-06')
            ->assertOk()
            ->json('people');

        expect(collect($people)->firstWhere('id', $this->ana->id))->toMatchArray(['capacity' => 2400, 'load' => 1200, 'collaborator' => false])
            ->and(collect($people)->firstWhere('id', $amparo->id))->toMatchArray(['collaborator' => true, 'has_schedule' => false, 'capacity' => 0]);

        $this->actingAs($this->ana)->getJson('/prevision/disponibilidad')->assertForbidden();
    });
});

describe('festivos y ausencias (D-301)', function () {
    it('cada columna lleva sus festivos y cada persona sus días de ausencia aprobada', function () {
        Holiday::factory()->create(['date' => '2026-11-04', 'name' => 'Fiesta local']);
        Absence::factory()->approved()->between('2026-11-09', '2026-11-13')->create(['user_id' => $this->ana->id]);
        Absence::factory()->approved()->partial(120)->between('2026-11-17', '2026-11-17')->create(['user_id' => $this->ana->id, 'type' => 'training']);
        // Solicitada: no resta.
        Absence::factory()->between('2026-11-23', '2026-11-27')->create(['user_id' => $this->ana->id]);

        $board = $this->combiner->board($this->period);
        $ana = ($this->person)($board, $this->ana);

        expect($board['holidays'][0])->toBe([['date' => '2026-11-04', 'name' => 'Fiesta local']])
            ->and($board['holidays'][1])->toBe([])
            ->and($ana['absences'][0])->toBeNull()
            ->and($ana['absences'][1])->toBe(['days' => 5, 'partial' => false, 'type' => 'vacation'])
            ->and($ana['cells'][1]['capacity'])->toBe(0)
            ->and($ana['absences'][2])->toBe(['days' => 1, 'partial' => true, 'type' => 'training'])
            ->and($ana['absences'][3])->toBeNull()
            ->and($ana['weekly_minutes'])->toBe(2400);
    });

    it('el tipo de la ausencia solo lo ve quien puede ver las ausencias de esa persona (D-088)', function () {
        Absence::factory()->approved()->between('2026-11-09', '2026-11-13')->create(['user_id' => $this->marta->id]);
        Absence::factory()->approved()->between('2026-11-09', '2026-11-13')->create(['user_id' => $this->ana->id]);

        // Raúl dirige Diseño: ve el tipo de Ana, pero no el de Marta (Desarrollo).
        $this->actingAs($this->manager)->get('/prevision?meses=1')
            ->assertInertia(fn (Assert $page) => $page->component('forecast/index')
                ->where('board.people', fn ($people) => collect($people)->firstWhere('id', $this->ana->id)['absences'][1]['type'] === 'vacation'
                    && collect($people)->firstWhere('id', $this->marta->id)['absences'][1] === ['days' => 5, 'partial' => false, 'type' => null]));

        $admin = userWithRole('admin');
        $this->actingAs($admin)->get('/prevision?meses=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.people', fn ($people) => collect($people)->firstWhere('id', $this->marta->id)['absences'][1]['type'] === 'vacation'));
    });
});

describe('/prevision (D-294 y D-302)', function () {
    it('por semanas hasta 3 meses y por meses desde 6, salvo que se pida', function (string $query, string $granularity) {
        $this->actingAs($this->manager)->get('/prevision'.$query)
            ->assertInertia(fn (Assert $page) => $page->where('filters.granularity', $granularity));
    })->with([
        'por defecto' => ['', 'week'],
        '2 meses' => ['?meses=2', 'week'],
        '6 meses' => ['?meses=6', 'month'],
        '12 meses por semanas' => ['?meses=12&por=semanas', 'week'],
        '3 meses por meses' => ['?meses=3&por=meses', 'month'],
    ]);

    it('debajo, los huecos sin persona y los previstos abiertos del periodo, en una petición aparte', function () {
        $open = ForecastProject::factory()->tentative()->create(['name' => 'Hotel Mar Azul', 'start_date' => '2026-11-16', 'end_date' => '2026-12-18']);
        ForecastProject::factory()->tentative()->create(['name' => 'Lejano', 'start_date' => '2027-06-01', 'end_date' => '2027-07-01']);
        ForecastProject::factory()->lost()->create(['name' => 'Perdido', 'start_date' => '2026-11-16', 'end_date' => '2026-12-18']);
        $gap = Allocation::factory()->forForecast($open)->gap($this->design)->total(4800)->between('2026-11-16', '2026-12-11')->create();
        Allocation::factory()->forProject($this->project)->gap($this->dev)->total(600)->between('2026-11-02', '2026-11-06')->create();

        $this->actingAs($this->manager)->get('/prevision')
            ->assertInertia(fn (Assert $page) => $page->missing('gaps')->missing('open_forecasts')
                ->loadDeferredProps('lists', fn (Assert $reload) => $reload
                    ->has('gaps', 2)
                    ->where('gaps.0.container.kind', 'project')
                    ->where('gaps.0.container.layer', 'real')
                    ->where('gaps.1.allocation.id', $gap->id)
                    ->where('gaps.1.allocation.planned_minutes', 4800)
                    ->where('gaps.1.allocation.can.assign', true)
                    ->where('gaps.1.container', ['kind' => 'forecast', 'id' => $open->id, 'name' => 'Hotel Mar Azul', 'client_name' => $open->clientName(), 'layer' => 'tentative'])
                    ->has('open_forecasts', 1)
                    ->where('open_forecasts.0.name', 'Hotel Mar Azul')));
    });
});

describe('Mi carga (D-305)', function () {
    beforeEach(function () {
        $this->forecast = ForecastProject::factory()->tentative()->create(['name' => 'Web y branding']);
        Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->percent(50)->between('2026-11-16', '2026-12-18')->create();
        Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(240)->between('2026-10-01', '2026-11-27')->create();
    });

    it('en Inicio, con la previsión, solo las asignaciones (también las posibles) en lugar de la carga por tareas', function () {
        $this->actingAs($this->ana)->get('/')
            ->assertInertia(fn (Assert $page) => $page->missing('workload')->missing('my_forecast')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->has('my_forecast.board.buckets', 26)
                    ->where('my_forecast.board.people.0.id', $this->ana->id)
                    ->where('my_forecast.board.people.0.cells.0.real', 5 * 240)
                    ->has('my_forecast.allocations', 2)
                    ->where('my_forecast.allocations.0.layer', 'real')
                    ->where('my_forecast.allocations.1.layer', 'tentative')
                    ->where('my_forecast.allocations.1.container.name', 'Web y branding')));
    });

    it('sin el módulo, Inicio sigue con la carga por tareas', function () {
        enableForecast(false);

        $this->actingAs($this->ana)->get('/')
            ->assertInertia(fn (Assert $page) => $page->missing('my_forecast')
                ->loadDeferredProps(fn (Assert $reload) => $reload->has('workload')->missing('my_forecast')));
    });

    it('en /carga, el empleado ve sus asignaciones; quien ve al equipo, no', function () {
        $this->actingAs($this->ana)->get('/carga')
            ->assertInertia(fn (Assert $page) => $page->component('workload/index')
                ->loadDeferredProps(fn (Assert $reload) => $reload->has('my_forecast.allocations', 2)));

        $this->actingAs($this->manager)->get('/carga')
            ->assertInertia(fn (Assert $page) => $page->where('my_forecast', null));
    });
});

describe('barra lateral y búsqueda (D-306)', function () {
    it('las habilidades: la previsión global para responsables y admins; la propia, para toda la plantilla', function () {
        $this->actingAs($this->manager)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewForecast', true)->where('auth.can.useForecast', true));
        $this->actingAs($this->ana)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewForecast', false)->where('auth.can.useForecast', true));

        enableForecast(false);
        $this->actingAs($this->manager)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewForecast', false)->where('auth.can.useForecast', false));
    });

    it('busca la página y los proyectos previstos, solo quien ve la previsión', function () {
        ForecastProject::factory()->create(['name' => 'Campaña de primavera']);

        $titles = fn (User $user, string $query): array => array_column($this->actingAs($user)->getJson('/buscar?q='.urlencode($query))->json('results'), 'title');

        expect($titles($this->manager, 'primavera'))->toContain('Campaña de primavera')
            ->and($titles($this->manager, 'previsión'))->toContain('Previsión')
            ->and($titles($this->ana, 'primavera'))->not->toContain('Campaña de primavera')
            ->and($titles($this->ana, 'previsión'))->not->toContain('Previsión');

        enableForecast(false);
        expect($titles($this->manager, 'primavera'))->not->toContain('Campaña de primavera');
    });
});
