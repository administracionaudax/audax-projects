<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\HourBanksAtRisk;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| R1 · Añadidos al contrato de la Fase 2 (app/Domain/Reports), con cifras calculadas a mano:
|  - Metrics::capacityByPerson: la capacidad de cada persona (capacityByDate es su suma),
|  - ReportScope::withoutFinancials: el mismo alcance sin valorar ingreso ni coste,
|  - HourBanksAtRisk: bolsas abiertas desde el primer umbral, con los filtros y el alcance D-044,
|  - OverdueTasks: tareas abiertas con la fecha límite pasada, con los filtros y el alcance D-044.
| Semana del 21 al 27/09/2026; «hoy» es el viernes 25 (Madrid).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->admin = User::factory()->admin()->create();
    $this->head = User::factory()->departmentManager()->create();
    $this->design->managers()->attach($this->head);

    $this->week = fn (array $query = []): ReportFilters => ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]);
    $this->queries = function (Closure $callback): int {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        $callback();
        app('events')->forget(QueryExecuted::class);

        return $count;
    };
});

describe('Metrics::capacityByPerson', function () {
    beforeEach(function () {
        // Ana: 8 h de lunes a viernes; Luis: 4 h. Bea, de baja, imputó por última vez el martes
        // 22: su capacidad acaba ese día (2 × 480). Carlos, de baja y sin horas, no cuenta.
        $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
        $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id]);
        $this->bea = User::factory()->employee()->create(['name' => 'Bea', 'department_id' => $this->design->id]);
        $carlos = User::factory()->employee()->create(['name' => 'Carlos', 'department_id' => $this->design->id, 'is_active' => false]);
        WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($this->luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
        WorkSchedule::factory()->for($this->bea)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($carlos)->create(['valid_from' => '2026-01-01']);
        TimeEntry::factory()->on('2026-09-22')->minutes(60)->create(['user_id' => $this->bea->id]);
        $this->bea->update(['is_active' => false]);
    });

    it('da la capacidad de cada persona por día y su suma es capacityByDate', function () {
        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));

        $byPerson = $metrics->capacityByPerson($scope);

        expect(array_keys($byPerson))->toEqualCanonicalizing([$this->ana->id, $this->luis->id, $this->bea->id])
            ->and(array_sum($byPerson[$this->ana->id]))->toBe(2400)
            ->and(array_sum($byPerson[$this->luis->id]))->toBe(1200)
            ->and($byPerson[$this->bea->id])->toBe(['2026-09-21' => 480, '2026-09-22' => 480])
            ->and($byPerson[$this->luis->id]['2026-09-26'])->toBe(0)
            ->and(array_sum($metrics->capacityByDate($scope)))->toBe(2400 + 1200 + 960)
            ->and($metrics->capacityByDate($scope)['2026-09-22'])->toBe(480 + 240 + 480);
    });

    it('calcula una vez por alcance y persona que mira en la misma instancia', function () {
        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)());

        $first = ($this->queries)(fn () => $metrics->capacityByPerson($scope));
        $again = ($this->queries)(fn () => $metrics->capacityByDate(new ReportScope($this->admin, ($this->week)())));

        expect($first)->toBeGreaterThan(0)->and($again)->toBe(0);

        // Otra persona que mira u otros filtros vuelven a calcular.
        expect(($this->queries)(fn () => $metrics->capacityByPerson(new ReportScope($this->head, ($this->week)()))))->toBeGreaterThan(0)
            ->and(($this->queries)(fn () => $metrics->capacityByPerson(new ReportScope($this->admin, ($this->week)(['persona' => [$this->ana->id]])))))->toBeGreaterThan(0);
    });

    it('un responsable solo tiene la capacidad de su equipo y la suya', function () {
        $outsider = User::factory()->employee()->create(['department_id' => $this->marketing->id]);
        WorkSchedule::factory()->for($outsider)->create(['valid_from' => '2026-01-01']);

        $byPerson = app(Metrics::class)->capacityByPerson(new ReportScope($this->head, ($this->week)()));

        expect(array_keys($byPerson))->toEqualCanonicalizing([$this->ana->id, $this->luis->id, $this->bea->id, $this->head->id]);
    });
});

describe('ReportScope::withoutFinancials', function () {
    it('quita el ingreso, el coste y el margen sin cambiar las horas ni consultar las tarifas', function () {
        $ana = User::factory()->employee()->create(['department_id' => $this->design->id, 'hourly_cost' => '20.00']);
        $project = Project::factory()->create(['hourly_rate' => '60.00']);
        TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $project->id]))->on('2026-09-22')->minutes(90)->create(['user_id' => $ana->id]);

        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)());
        $plain = $scope->withoutFinancials();

        $full = $metrics->summary($scope);
        $rates = 0;
        DB::listen(function (QueryExecuted $query) use (&$rates): void {
            $rates += str_contains($query->sql, '"projects"') || str_contains($query->sql, '"hour_banks"') ? 1 : 0;
        });
        $without = $metrics->summary($plain);

        expect($full['income'])->toBe('90.00')
            ->and($full['cost'])->toBe('30.00')
            ->and($scope->canSeeFinancials())->toBeTrue()
            ->and($plain->canSeeFinancials())->toBeFalse()
            ->and($without['logged_minutes'])->toBe(90)
            ->and($without['income'])->toBeNull()
            ->and($without['cost'])->toBeNull()
            ->and($without['margin'])->toBeNull()
            ->and($metrics->series($plain, Dimension::Day)[1]['income'])->toBeNull()
            ->and($rates)->toBe(0);

        // Con otros filtros vuelve a mirar el permiso.
        expect($plain->withFilters(($this->week)(['facturable' => 'si']))->canSeeFinancials())->toBeTrue();
    });
});

describe('HourBanksAtRisk', function () {
    beforeEach(function () {
        $this->luis = User::factory()->employee()->create(['department_id' => $this->design->id]);
        $this->marta = User::factory()->employee()->create(['department_id' => $this->marketing->id]);
        $this->clientA = Client::factory()->create(['name' => 'A']);

        // Consumo (dentro de la bolsa / total) → en riesgo desde el 75 % (primer umbral por defecto).
        $this->bank = function (int $total, User $user, int $minutes, array $attributes = []): HourBank {
            $bank = HourBank::factory()->create(['total_minutes' => $total, ...$attributes]);
            TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->on('2026-09-21')->minutes($minutes)->create(['user_id' => $user->id]);

            return $bank->fresh() ?? $bank;
        };
        $this->b78 = ($this->bank)(1000, $this->luis, 780);                                            // 78 %
        $this->b90 = ($this->bank)(600, $this->marta, 540, ['department_id' => $this->marketing->id]);  // 90 %
        $this->b50 = ($this->bank)(600, $this->luis, 300);                                             // 50 %: nunca
        $this->b120 = ($this->bank)(600, $this->marta, 720);                                           // 100 % + 120 de exceso
        $this->b120->project->update(['client_id' => $this->clientA->id]);
    });

    it('ordena de más a menos consumida (dentro de la bolsa) y usa el primer umbral configurado', function () {
        $atRisk = app(HourBanksAtRisk::class);
        $scope = new ReportScope($this->admin, ($this->week)());

        $result = $atRisk->forScope($scope);
        expect($result['threshold'])->toBe(75)
            ->and($result['count'])->toBe(3)
            ->and(array_column($result['banks'], 'id'))->toBe([$this->b120->id, $this->b90->id, $this->b78->id])
            ->and($result['banks'][0])->toMatchArray(['status' => 'exhausted', 'consumed_minutes' => 720, 'overage_minutes' => 120, 'ratio' => 1.0])
            ->and($result['banks'][0]['client'])->toBe('A')
            ->and($result['banks'][2]['ratio'])->toBe(0.78);

        // Con el primer umbral en el 80 %, la del 78 % ya no está en riesgo; el límite corta la lista, no el recuento.
        Setting::set('hour_bank_alert_thresholds', [100, 80]);
        $result = $atRisk->forScope($scope, limit: 1);
        expect($result['threshold'])->toBe(80)
            ->and($result['count'])->toBe(2)
            ->and(array_column($result['banks'], 'id'))->toBe([$this->b120->id]);
    });

    it('aplica los filtros de cliente, proyecto, bolsa y persona', function () {
        $ids = fn (array $query): array => array_column(app(HourBanksAtRisk::class)->forScope(new ReportScope($this->admin, ($this->week)($query)))['banks'], 'id');

        expect($ids(['cliente' => [$this->clientA->id]]))->toBe([$this->b120->id])
            ->and($ids(['proyecto' => [$this->b90->project_id, $this->b50->project_id]]))->toBe([$this->b90->id])
            ->and($ids(['bolsa' => [$this->b78->id, $this->b50->id]]))->toBe([$this->b78->id])
            // Personas: las bolsas de los proyectos donde han imputado.
            ->and($ids(['persona' => [$this->luis->id]]))->toBe([$this->b78->id])
            // Departamento: las suyas y las de proyectos donde ha imputado alguien del departamento.
            ->and($ids(['departamento' => [$this->marketing->id]]))->toBe([$this->b120->id, $this->b90->id]);
    });

    it('un responsable ve las de su departamento y las de proyectos con horas de su equipo; un gestor, las de sus proyectos', function () {
        $designBank = ($this->bank)(100, $this->marta, 80, ['department_id' => $this->design->id]);

        $head = app(HourBanksAtRisk::class)->forScope(new ReportScope($this->head, ($this->week)()));
        expect(array_column($head['banks'], 'id'))->toBe([$designBank->id, $this->b78->id]);

        // Aunque pida Marketing con el filtro, se queda en los suyos.
        $head = app(HourBanksAtRisk::class)->forScope(new ReportScope($this->head, ($this->week)(['departamento' => [$this->marketing->id]])));
        expect($head['count'])->toBe(2);

        $manager = User::factory()->employee()->create();
        $this->b90->project->addMember($manager, isManager: true);
        $managed = app(HourBanksAtRisk::class)->forScope(new ReportScope($manager, ($this->week)()));
        expect(array_column($managed['banks'], 'id'))->toBe([$this->b90->id]);
    });
});

describe('OverdueTasks', function () {
    beforeEach(function () {
        $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id]);
        $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $this->marketing->id]);
        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->other = Project::factory()->create();
        $this->type = TaskType::factory()->create();
        $this->bankTask = Task::factory()->inBank(HourBank::factory()->create(['project_id' => $this->other->id]))
            ->create(['assignee_user_id' => $this->marta->id, 'due_date' => '2026-09-15', 'title' => 'En bolsa']);

        $this->old = Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-01', 'title' => 'Antigua', 'task_type_id' => $this->type->id]);
        $this->milestone = Task::factory()->milestone()->create(['project_id' => $this->other->id, 'assignee_user_id' => null, 'due_date' => '2026-09-24', 'title' => 'Hito']);
        Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-25']);            // vence hoy
        Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => null]);                    // sin fecha
        Task::factory()->completed()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-02']);
    });

    it('cuenta y lista las vencidas de la más antigua a la más reciente, con los días de retraso', function () {
        $result = app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)()), limit: 2);

        expect($result['count'])->toBe(3)
            ->and(array_column($result['tasks'], 'title'))->toBe(['Antigua', 'En bolsa'])
            ->and($result['tasks'][0])->toMatchArray(['due_date' => '2026-09-01', 'days_overdue' => 24, 'assignee' => 'Luis', 'is_milestone' => false])
            ->and($result['tasks'][0]['project']['code'])->toBe($this->project->code);

        $milestone = collect(app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)()))['tasks'])->firstWhere('id', $this->milestone->id);
        expect($milestone)->toMatchArray(['is_milestone' => true, 'assignee' => null, 'days_overdue' => 1]);
    });

    it('aplica los filtros de cliente, proyecto, bolsa, tipo y persona; el periodo no cuenta', function () {
        $titles = fn (array $query): array => array_column(app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)($query)))['tasks'], 'title');

        expect($titles(['cliente' => [$this->client->id]]))->toBe(['Antigua'])
            ->and($titles(['proyecto' => [$this->other->id]]))->toBe(['En bolsa', 'Hito'])
            ->and($titles(['bolsa' => [$this->bankTask->hour_bank_id]]))->toBe(['En bolsa'])
            ->and($titles(['tipo' => [$this->type->id]]))->toBe(['Antigua'])
            ->and($titles(['persona' => [$this->marta->id]]))->toBe(['En bolsa'])
            ->and($titles(['periodo' => 'mes', 'fecha' => '2025-01-01']))->toBe(['Antigua', 'En bolsa', 'Hito']);
    });

    it('un responsable solo ve las asignadas a su equipo (y a él)', function () {
        $result = app(OverdueTasks::class)->forScope(new ReportScope($this->head, ($this->week)()));

        expect($result['count'])->toBe(1)->and($result['tasks'][0]['id'])->toBe($this->old->id);
    });
});
