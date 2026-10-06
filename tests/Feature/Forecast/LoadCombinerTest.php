<?php

use App\Domain\Forecast\ForecastPeriod;
use App\Domain\Forecast\LoadCombiner;
use App\Enums\ProjectStatus;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Carga de la previsión (docs/PLAN-CARGAS.md §6.3 a §6.5 con P5, P6, P7 y P8; D-283): capacidad
| frente a asignado por persona y departamento, en tres capas, solo con asignaciones.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    $this->combiner = app(LoadCombiner::class);

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id]);
    $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $this->dev->id]);

    // Fuera de la plantilla de la previsión: colaborador externo, cliente y desactivado.
    User::factory()->collaborator()->create(['department_id' => $this->design->id]);
    User::factory()->employee()->inactive()->create(['department_id' => $this->design->id]);

    $this->project = Project::factory()->create();
    $this->period = ForecastPeriod::make(CarbonImmutable::parse('2026-11-02'), 2);

    $this->department = fn (array $board, ?int $id): array => collect($board['departments'])->firstWhere('id', $id);
    $this->person = fn (array $board, User $user): array => collect($board['people'])->firstWhere('id', $user->id);
});

it('la capacidad es la jornada de cada persona de plantilla y la del departamento, la suma de sus personas', function () {
    $board = $this->combiner->board($this->period);

    // Noviembre de 2026: 21 días laborables; diciembre: 23.
    expect(array_column($board['buckets'], 'key'))->toBe(['2026-11', '2026-12'])
        ->and(($this->person)($board, $this->ana)['cells'][0]['capacity'])->toBe(21 * 480)
        ->and(($this->department)($board, $this->design->id)['cells'][0]['capacity'])->toBe(2 * 21 * 480)
        ->and(($this->department)($board, $this->design->id)['people'])->toBe(2)
        ->and(($this->department)($board, $this->dev->id)['cells'][1]['capacity'])->toBe(23 * 480)
        ->and(collect($board['people'])->pluck('name')->all())->toBe(['Ana', 'Luis', 'Marta']);
});

it('separa real, previsto seguro y previsto posible, y los huecos suman al departamento', function () {
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(240)->between('2026-11-02', '2026-11-06')->create();
    $firm = ForecastProject::factory()->firm()->create();
    Allocation::factory()->forForecast($firm)->forUser($this->luis)->total(960)->between('2026-11-09', '2026-11-10')->create();
    $tentative = ForecastProject::factory()->tentative()->create();
    Allocation::factory()->forForecast($tentative)->gap($this->design)->total(1920)->between('2026-12-01', '2026-12-04')->create();
    // Un confirmado cuenta como seguro.
    $confirmed = ForecastProject::factory()->confirmed()->create();
    Allocation::factory()->forForecast($confirmed)->forUser($this->marta)->total(600)->between('2026-12-07', '2026-12-07')->create();

    $board = $this->combiner->board($this->period);
    $design = ($this->department)($board, $this->design->id);

    expect(($this->person)($board, $this->ana)['cells'][0])->toMatchArray(['real' => 1200, 'firm' => 0, 'tentative' => 0])
        ->and(($this->person)($board, $this->luis)['cells'][0])->toMatchArray(['real' => 0, 'firm' => 960, 'tentative' => 0])
        ->and(($this->person)($board, $this->marta)['cells'][1])->toMatchArray(['firm' => 600])
        ->and($design['cells'][0])->toMatchArray(['real' => 1200, 'firm' => 960, 'tentative' => 0])
        ->and($design['cells'][1])->toMatchArray(['real' => 0, 'firm' => 0, 'tentative' => 1920])
        ->and($design['gaps'][1])->toBe(['real' => 0, 'firm' => 0, 'tentative' => 1920])
        ->and($board['totals'][0])->toMatchArray(['real' => 1200, 'firm' => 960])
        ->and($board['totals'][1])->toMatchArray(['firm' => 600, 'tentative' => 1920])
        ->and($board['totals'][0]['capacity'])->toBe(count($board['people']) * 21 * 480)
        ->and(collect($board['sources'])->pluck('layer')->sort()->values()->all())->toBe(['firm', 'firm', 'real', 'tentative']);
});

it('no cuentan los proyectos en pausa, completados, archivados o borrados, ni los previstos perdidos, vinculados o borrados', function () {
    foreach ([ProjectStatus::OnHold, ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
        $project = Project::factory()->create(['status' => $status]);
        Allocation::factory()->forProject($project)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create();
    }
    $trashed = Project::factory()->create();
    Allocation::factory()->forProject($trashed)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create();
    $trashed->delete();

    Allocation::factory()->forForecast(ForecastProject::factory()->lost()->create())->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create();
    $linked = ForecastProject::factory()->create(['status' => 'linked', 'project_id' => Project::factory()->create()->id]);
    Allocation::factory()->forForecast($linked)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create();
    $deleted = ForecastProject::factory()->create();
    Allocation::factory()->forForecast($deleted)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create();
    $deleted->delete();
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-30')->create()->delete();

    $board = $this->combiner->board($this->period);

    expect(($this->person)($board, $this->ana)['cells'][0])->toMatchArray(['real' => 0, 'firm' => 0, 'tentative' => 0])
        ->and($board['sources'])->toBe([]);
});

it('las horas estimadas de las tareas y las bolsas no cuentan: solo las asignaciones (P6 y P7)', function () {
    $bank = HourBank::factory()->hours(100)->create(['project_id' => $this->project->id]);
    Task::factory()->assignedTo($this->ana)->create([
        'project_id' => $this->project->id,
        'hour_bank_id' => $bank->id,
        'estimated_minutes' => 6000,
        'start_date' => '2026-11-02',
        'due_date' => '2026-11-20',
    ]);
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->total(600)->between('2026-11-02', '2026-11-06')->create();

    $board = $this->combiner->board($this->period);

    expect(($this->person)($board, $this->ana)['cells'][0]['real'])->toBe(600)
        ->and(($this->person)($board, $this->ana)['cells'][1]['real'])->toBe(0);
});

it('cuenta de hoy en adelante: los días pasados del periodo no tienen carga ni capacidad', function () {
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(60)->between('2026-10-26', '2026-11-03')->create();

    $board = $this->combiner->board(ForecastPeriod::make(CarbonImmutable::parse('2026-10-01'), 2));
    $ana = ($this->person)($board, $this->ana);

    expect($board['period']['counts_from'])->toBe('2026-11-02')
        ->and($ana['cells'][0])->toBe(['capacity' => 0, 'real' => 0, 'firm' => 0, 'tentative' => 0])
        ->and($ana['cells'][1])->toMatchArray(['capacity' => 21 * 480, 'real' => 120]);
});

it('en un proyecto real, el restante vencido de una persona va a hoy', function () {
    $allocation = Allocation::factory()->forProject($this->project)->forUser($this->ana)->total(900)->between('2026-10-19', '2026-10-23')->create();
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    TimeEntry::factory()->forTask($task)->on('2026-10-20')->minutes(300)->create(['user_id' => $this->ana->id]);

    $board = $this->combiner->board($this->period);

    expect(($this->person)($board, $this->ana)['cells'][0]['real'])->toBe(600)
        ->and($board['overdue'])->toBe([$allocation->id]);
});

it('por semanas, columnas ISO de lunes a domingo', function () {
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->perDay(60)->between('2026-11-02', '2026-11-13')->create();

    $board = $this->combiner->board(ForecastPeriod::make(CarbonImmutable::parse('2026-11-04'), 1, ForecastPeriod::WEEK));

    expect($board['buckets'][0])->toBe(['key' => '2026-W45', 'from' => '2026-11-02', 'to' => '2026-11-08'])
        ->and(($this->person)($board, $this->ana)['cells'][0]['real'])->toBe(300)
        ->and(($this->person)($board, $this->ana)['cells'][1]['real'])->toBe(300)
        ->and(end($board['buckets'])['to'])->toBe('2026-12-06');
});

it('filtra por departamento: sus personas, su capacidad y sus huecos', function () {
    Allocation::factory()->forForecast()->gap($this->dev)->total(480)->between('2026-11-02', '2026-11-02')->create();
    Allocation::factory()->forForecast()->gap($this->design)->total(480)->between('2026-11-02', '2026-11-02')->create();

    $board = $this->combiner->board($this->period, ['department_ids' => [$this->dev->id]]);

    expect(collect($board['people'])->pluck('id')->all())->toBe([$this->marta->id])
        ->and(collect($board['departments'])->pluck('id')->all())->toBe([$this->dev->id])
        ->and($board['totals'][0]['tentative'])->toBe(480);
});

it('«mi carga» (P8): todas mis asignaciones, también las de previstos posibles, sin los demás', function () {
    $tentative = ForecastProject::factory()->tentative()->create(['name' => 'Hotel Mar Azul']);
    Allocation::factory()->forForecast($tentative)->forUser($this->ana)->total(480)->between('2026-11-02', '2026-11-02')->create();
    Allocation::factory()->forProject($this->project)->forUser($this->ana)->total(480)->between('2026-11-03', '2026-11-03')->create();
    Allocation::factory()->forProject($this->project)->forUser($this->luis)->total(480)->between('2026-11-03', '2026-11-03')->create();
    Allocation::factory()->forForecast($tentative)->gap($this->design)->total(480)->between('2026-11-03', '2026-11-03')->create();

    $board = $this->combiner->board($this->period, ['user_ids' => [$this->ana->id]]);

    expect(collect($board['people'])->pluck('id')->all())->toBe([$this->ana->id])
        ->and($board['departments'])->toBe([])
        ->and($board['totals'][0])->toMatchArray(['real' => 480, 'firm' => 0, 'tentative' => 480])
        ->and(collect($board['sources'])->pluck('forecast.name')->filter()->values()->all())->toBe(['Hotel Mar Azul']);
});

it('una persona sin departamento sale en «sin departamento»', function () {
    $loner = User::factory()->employee()->create(['department_id' => null]);
    Allocation::factory()->forProject($this->project)->forUser($loner)->total(480)->between('2026-11-02', '2026-11-02')->create();

    $board = $this->combiner->board($this->period);
    $none = ($this->department)($board, null);

    expect($none['people'])->toBe(1)
        ->and($none['cells'][0])->toMatchArray(['capacity' => 21 * 480, 'real' => 480]);
});
