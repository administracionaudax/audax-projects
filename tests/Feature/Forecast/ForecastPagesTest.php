<?php

use App\Domain\Forecast\ForecastLinker;
use App\Domain\Forecast\ForecastPeriod;
use App\Domain\Forecast\LoadCombiner;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Contrato de las páginas de la previsión (resources/js/types/forecast.ts): las props de cada
| página, las diferidas y el presupuesto de consultas (30 personas × 12 meses < 300 ms, sin N+1).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    enableForecast();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->manager = userWithRole('department_manager', ['department_id' => $this->design->id]);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
    $this->project = Project::factory()->create(['owner_user_id' => $this->manager->id]);
    $this->forecast = ForecastProject::factory()->create(['name' => 'Hotel Mar Azul', 'owner_user_id' => $this->manager->id, 'start_date' => '2026-11-02', 'end_date' => '2026-12-31']);
    Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->total(2400)->between('2026-11-02', '2026-11-06')->create();
    Allocation::factory()->forForecast($this->forecast)->gap($this->design)->monthly(1200)->between('2026-11-01', null)->create();
});

it('/prevision: el tablero, los filtros y los departamentos', function () {
    $this->actingAs($this->manager)->get('/prevision?meses=2&por=semanas&departamento='.$this->design->id)
        ->assertInertia(fn (Assert $page) => $page->component('forecast/index')
            ->where('filters', ['from' => '2026-11-02', 'months' => 2, 'granularity' => 'week', 'department_id' => $this->design->id])
            ->where('board.period.granularity', 'week')
            ->where('board.buckets.0.key', '2026-W45')
            ->has('board.people', 2)
            ->has('board.departments', 1)
            ->has('board.departments.0', fn (Assert $department) => $department->where('id', $this->design->id)->where('people', 2)->etc())
            ->has('board.sources', 2)
            ->has('departments', 1)
            ->where('can.manage', true));
});

it('/prevision/mi-carga: la carga propia por semanas, en JSON', function () {
    $this->actingAs($this->ana)->getJson('/prevision/mi-carga')
        ->assertOk()
        ->assertJsonPath('period.granularity', 'week')
        ->assertJsonCount(1, 'people')
        ->assertJsonPath('people.0.id', $this->ana->id)
        ->assertJsonPath('people.0.cells.0.tentative', 2400)
        ->assertJsonPath('departments', []);
});

it('/prevision/proyectos: la lista con las horas del plan completo', function () {
    ForecastProject::factory()->lost()->create();

    $this->actingAs($this->manager)->get('/prevision/proyectos')
        ->assertInertia(fn (Assert $page) => $page->component('forecast/projects/index')
            ->where('filters.status', 'active')
            ->has('projects', 1)
            ->where('projects.0.name', 'Hotel Mar Azul')
            // 40 h + 20 h en noviembre y 20 h en diciembre (la mensual sin fin llega al fin del previsto).
            ->where('projects.0.allocated_minutes', 2400 + 1200 + 1200)
            ->where('projects.0.can.confirm', true)
            ->missing('clients')
            ->where('can.create', true));

    $this->actingAs($this->manager)->get('/prevision/proyectos?estado=all')
        ->assertInertia(fn (Assert $page) => $page->has('projects', 2));
});

it('/prevision/proyectos/{id}: la ficha, y el impacto y las opciones en una petición aparte', function () {
    $this->actingAs($this->manager)->get("/prevision/proyectos/{$this->forecast->id}")
        ->assertInertia(fn (Assert $page) => $page->component('forecast/projects/show')
            ->where('forecast.id', $this->forecast->id)
            ->where('forecast.client_name', $this->forecast->prospect_name)
            ->has('allocations', 2)
            // Por inicio: primero el hueco mensual (desde el 1 de noviembre) y después Ana.
            ->where('allocations.0.is_gap', true)
            ->where('allocations.0.months', ['2026-11' => 1200, '2026-12' => 1200])
            ->where('allocations.0.can.assign', true)
            ->where('allocations.1.planned_minutes', 2400)
            ->where('allocations.1.months', ['2026-11' => 2400])
            ->where('allocations.1.can', ['update' => true, 'assign' => false])
            ->where('months', ['2026-11', '2026-12'])
            ->where('totals', ['allocated_minutes' => 4800, 'estimated_minutes' => null, 'difference_minutes' => null])
            ->missing('impact')
            ->missing('estimate')
            ->loadDeferredProps('analysis', fn (Assert $reload) => $reload
                ->where('impact.layer', 'tentative')
                ->has('impact.departments', 1)
                ->has('impact.people', 1)
                ->where('estimate', null))
            ->loadDeferredProps('options', fn (Assert $reload) => $reload
                ->has('options.people', 2)
                ->has('options.departments', 1)
                ->has('options.link_candidates', 1)));
});

it('/proyectos/{id}/planificacion: plan, imputado, restante, semanas y el previsto de origen', function () {
    app(ForecastLinker::class)->link($this->forecast, $this->project, $this->manager);
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    TimeEntry::factory()->forTask($task)->on('2026-11-02')->minutes(600)->create(['user_id' => $this->ana->id]);

    $this->actingAs($this->manager)->get("/proyectos/{$this->project->id}/planificacion")
        ->assertInertia(fn (Assert $page) => $page->component('projects/planning')
            ->where('project.id', $this->project->id)
            ->has('allocations', 2)
            ->where('allocations.1.planned_minutes', 2400)
            ->where('allocations.1.logged_minutes', 600)
            // Restante de un total de una persona: 2.400 − 600 entre los 5 días desde hoy.
            ->where('allocations.1.remaining_minutes', 1800)
            // El hueco mensual sin fin: un año en un proyecto sin fecha de entrega.
            ->where('allocations.0.logged_minutes', null)
            ->where('allocations.0.months.2026-11', 1200)
            ->where('forecast.id', $this->forecast->id)
            ->where('can.manage', true)
            ->has('weeks')
            ->where('weeks.0', ['key' => '2026-W44', 'from' => '2026-10-26', 'to' => '2026-11-01', 'planned' => 0, 'logged' => 0])
            ->where('weeks.1.logged', 600)
            ->where('totals.logged_minutes', 600)
            ->loadDeferredProps('analysis', fn (Assert $reload) => $reload->where('estimate.totals.actual', 600)));
});

it('rendimiento: 30 personas × 12 meses en menos de 300 ms y con las mismas consultas', function () {
    $departments = Department::factory()->count(3)->sequence(['name' => 'A'], ['name' => 'B'], ['name' => 'C'])->create();

    $grow = function (int $people) use ($departments): void {
        foreach (range(1, $people) as $i) {
            $user = User::factory()->employee()->create(['department_id' => $departments[$i % 3]->id]);
            $project = Project::factory()->create();
            Allocation::factory()->forProject($project)->forUser($user)->total(9600)->between('2026-11-02', '2027-02-26')->create();
            Allocation::factory()->forProject($project)->forUser($user)->perDay(120)->between('2026-12-01', '2027-06-30')->create();
            Allocation::factory()->forForecast($this->forecast)->forUser($user)->percent(25)->between('2027-01-04', '2027-10-29')->create();
            Allocation::factory()->forForecast()->forUser($user)->monthly(1200)->between('2026-11-01', null)->create();
            Allocation::factory()->forForecast()->gap($departments[$i % 3])->total(4800)->between('2027-03-01', '2027-04-30')->create();
        }
    };

    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->manager)->get('/prevision?meses=12')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // El cálculo de la carga (LoadCombiner), el mejor de tres para no medir el ruido de la máquina
    // (los tests van en paralelo).
    $time = function (): float {
        $period = ForecastPeriod::make(CarbonImmutable::parse('2026-11-02'), 12);
        $best = INF;

        foreach (range(1, 3) as $attempt) {
            $start = hrtime(true);
            app(LoadCombiner::class)->board($period);
            $best = min($best, (hrtime(true) - $start) / 1e6);
        }

        return $best;
    };

    $grow(3);
    $measure();
    $few = $measure();
    $grow(27);
    $measure();

    expect($measure())->toBe($few)
        ->and(User::query()->count())->toBeGreaterThanOrEqual(30)
        ->and($time())->toBeLessThan(300.0);
});
