<?php

use App\Domain\Forecast\AllocationWriter;
use App\Domain\Forecast\EstimateVsActual;
use App\Domain\Forecast\ForecastImpact;
use App\Domain\Forecast\ForecastLinker;
use App\Domain\Forecast\ForecastProjectWriter;
use App\Enums\AllocationMode;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastStatus;
use App\Enums\ProjectStatus;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| El ciclo de un proyecto previsto (docs/PLAN-CARGAS.md §5.2, §6.5 a §6.7; D-281, D-282, D-285 a
| D-287): escribir previstos y asignaciones, el impacto «sin / con», vincular (copiar y congelar),
| desvincular y estimado frente a real.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->dev = Department::factory()->create(['name' => 'Desarrollo']);
    $this->manager = userWithRole('department_manager', ['department_id' => $this->design->id]);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->dev->id]);

    $this->forecasts = app(ForecastProjectWriter::class);
    $this->allocations = app(AllocationWriter::class);
    $this->linker = app(ForecastLinker::class);

    $this->forecast = $this->forecasts->create(['name' => 'Hotel Mar Azul · Web', 'prospect_name' => 'Hotel Mar Azul', 'start_date' => '2026-11-02', 'end_date' => '2026-12-18'], $this->manager);
});

describe('previstos', function () {
    it('se crean abiertos y posibles, con cliente o con nombre libre (uno de los dos)', function () {
        expect($this->forecast->status)->toBe(ForecastStatus::Open)
            ->and($this->forecast->confidence)->toBe(ForecastConfidence::Tentative)
            ->and($this->forecast->owner_user_id)->toBe($this->manager->id)
            ->and($this->forecast->color)->toStartWith('#');

        $client = Client::factory()->create();
        $withClient = $this->forecasts->create(['name' => 'Otro', 'client_id' => $client->id, 'prospect_name' => 'Sobra'], $this->manager);
        expect($withClient->prospect_name)->toBeNull();

        expect(fn () => $this->forecasts->create(['name' => 'Sin cliente'], $this->manager))->toThrow(ValidationException::class);
    });

    it('confirmar lo hace seguro; perdido deja de contar con su motivo; reabrir lo devuelve', function () {
        $this->forecasts->confirm($this->forecast);
        expect($this->forecast->status)->toBe(ForecastStatus::Confirmed)
            ->and($this->forecast->confidence)->toBe(ForecastConfidence::Firm);

        expect(fn () => $this->forecasts->update($this->forecast, ['confidence' => 'tentative'], $this->manager))->toThrow(ValidationException::class);

        $this->forecasts->lose($this->forecast, ' Precio ');
        expect($this->forecast->status)->toBe(ForecastStatus::Lost)
            ->and($this->forecast->lost_reason)->toBe('Precio')
            ->and($this->forecast->layer())->toBeNull();

        expect(fn () => $this->forecasts->confirm($this->forecast))->toThrow(ValidationException::class);

        $this->forecasts->reopen($this->forecast);
        expect($this->forecast->status)->toBe(ForecastStatus::Open)->and($this->forecast->lost_reason)->toBeNull();
    });

    it('el importe estimado solo se guarda con view-financials', function () {
        $this->forecasts->update($this->forecast, ['estimated_amount' => '18000.00'], $this->manager);
        expect($this->forecast->fresh()->estimated_amount)->toBeNull();

        $this->forecasts->update($this->forecast, ['estimated_amount' => '18000.00'], userWithRole('admin'));
        expect($this->forecast->fresh()->estimated_amount)->toBe('18000.00');
    });

    it('queda en la auditoría', function () {
        $this->forecasts->confirm($this->forecast);

        expect(Activity::query()->where('log_name', 'forecast_projects')->where('subject_id', $this->forecast->id)->count())->toBe(2);
    });
});

describe('asignaciones', function () {
    it('valida persona o hueco, la persona de plantilla, la cantidad según el modo y las fechas', function (array $data, string $field) {
        $base = ['user_id' => $this->ana->id, 'mode' => 'total', 'minutes' => 600, 'start_date' => '2026-11-02', 'end_date' => '2026-11-06'];
        $data = array_map(fn ($value) => $value === 'client' ? User::factory()->client()->create()->id : ($value === 'inactive' ? User::factory()->collaborator()->inactive()->create()->id : ($value === 'department' ? $this->design->id : $value)), $data);

        try {
            $this->allocations->create($this->forecast, [...$base, ...$data], $this->manager);
            $this->fail('Debía fallar');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }
    })->with([
        'persona y hueco a la vez' => [['department_id' => 'department'], 'user_id'],
        'ni persona ni hueco' => [['user_id' => null], 'user_id'],
        'un cliente' => [['user_id' => 'client'], 'user_id'],
        'un colaborador desactivado' => [['user_id' => 'inactive'], 'user_id'],
        'sin modo' => [['mode' => 'weekly'], 'mode'],
        'total sin horas' => [['minutes' => null], 'minutes'],
        'porcentaje fuera de rango' => [['mode' => 'percent', 'percent' => 250], 'percent'],
        'sin fin y no mensual' => [['end_date' => null], 'end_date'],
        'fin antes del inicio' => [['end_date' => '2026-10-01'], 'end_date'],
        'más de tres años' => [['end_date' => '2030-01-01'], 'end_date'],
        'fecha inválida' => [['start_date' => '2026-02-30'], 'start_date'],
    ]);

    it('a un colaborador externo activo se le pueden asignar horas (D-300)', function () {
        $amparo = User::factory()->collaborator()->create();
        $allocation = $this->allocations->create($this->forecast, ['user_id' => $amparo->id, 'mode' => 'total', 'minutes' => 600, 'start_date' => '2026-11-02', 'end_date' => '2026-11-06'], $this->manager);

        expect($allocation->user_id)->toBe($amparo->id);
    });

    it('un modo mensual puede no tener fin, y la cantidad que no toca se descarta', function () {
        $allocation = $this->allocations->create($this->forecast, ['department_id' => $this->design->id, 'mode' => 'monthly', 'minutes' => 1200, 'percent' => 50, 'start_date' => '2026-11-01', 'end_date' => null], $this->manager);

        expect($allocation->mode)->toBe(AllocationMode::Monthly)
            ->and($allocation->percent)->toBeNull()
            ->and($allocation->end_date)->toBeNull()
            ->and($allocation->isGap())->toBeTrue()
            ->and($allocation->created_by)->toBe($this->manager->id);
    });

    it('«Asignar a…» pasa un hueco a una persona y conserva lo demás', function () {
        $gap = $this->allocations->create($this->forecast, ['department_id' => $this->design->id, 'mode' => 'total', 'minutes' => 4800, 'start_date' => '2026-11-03', 'end_date' => '2026-11-28', 'note' => 'Diseño web'], $this->manager);

        $this->allocations->assign($gap, $this->ana);

        expect($gap->fresh())->user_id->toBe($this->ana->id)
            ->department_id->toBeNull()
            ->minutes->toBe(4800)
            ->note->toBe('Diseño web');

        expect(fn () => $this->allocations->assign($gap->fresh(), $this->luis))->toThrow(ValidationException::class);
    });

    it('no se tocan las de un previsto perdido o vinculado, ni las de un proyecto archivado', function () {
        $allocation = Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->create();
        $this->forecasts->lose($this->forecast, 'Precio');

        expect(fn () => $this->allocations->update($allocation->fresh(), ['minutes' => 60]))->toThrow(ValidationException::class)
            ->and(fn () => $this->allocations->create($this->forecast, ['user_id' => $this->ana->id, 'mode' => 'total', 'minutes' => 60, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'], $this->manager))->toThrow(ValidationException::class);

        $archived = Project::factory()->archived()->create();
        expect(fn () => $this->allocations->create($archived, ['user_id' => $this->ana->id, 'mode' => 'total', 'minutes' => 60, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'], $this->manager))->toThrow(ValidationException::class);
    });
});

describe('impacto «sin / con»', function () {
    it('por semana (hasta 16), la carga de cada departamento y persona sin el previsto y con él', function () {
        $project = Project::factory()->create();
        Allocation::factory()->forProject($project)->forUser($this->ana)->perDay(240)->between('2026-11-02', '2026-11-30')->create();
        Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->perDay(240)->between('2026-11-02', '2026-11-06')->create();
        Allocation::factory()->forForecast($this->forecast)->gap($this->dev)->total(1200)->between('2026-12-01', '2026-12-18')->create();

        $impact = app(ForecastImpact::class)->for($this->forecast);
        $ana = collect($impact['people'])->firstWhere('id', $this->ana->id);
        $dev = collect($impact['departments'])->firstWhere('id', $this->dev->id);
        $design = collect($impact['departments'])->firstWhere('id', $this->design->id);

        expect($impact['layer'])->toBe('tentative')
            ->and($impact['granularity'])->toBe('week')
            ->and(array_column($impact['buckets'], 'key'))->toBe(['2026-W45', '2026-W46', '2026-W47', '2026-W48', '2026-W49', '2026-W50', '2026-W51'])
            ->and($ana['cells'][0])->toBe(['capacity' => 5 * 480, 'without' => 5 * 240, 'with' => 10 * 240])
            ->and($ana['cells'][1])->toBe(['capacity' => 5 * 480, 'without' => 5 * 240, 'with' => 5 * 240])
            ->and(array_sum(array_map(fn (array $cell): int => $cell['with'] - $cell['without'], $dev['cells'])))->toBe(1200)
            ->and($design['cells'][0]['with'] - $design['cells'][0]['without'])->toBe(1200)
            ->and(collect($impact['people'])->pluck('id')->all())->toBe([$this->ana->id]);
    });

    it('por mes si dura más de 16 semanas', function () {
        Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->perDay(240)->between('2026-11-02', '2027-04-30')->create();

        $impact = app(ForecastImpact::class)->for($this->forecast);

        expect($impact['granularity'])->toBe('month')
            ->and(array_column($impact['buckets'], 'key'))->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04'])
            ->and(collect($impact['people'])->firstWhere('id', $this->ana->id)['cells'][0])->toBe(['capacity' => 21 * 480, 'without' => 0, 'with' => 21 * 240]);
    });

    it('no hay impacto de un previsto que no cuenta', function () {
        $this->forecasts->lose($this->forecast, '');

        expect(app(ForecastImpact::class)->for($this->forecast))->toBeNull();
    });
});

describe('vincular y estimado frente a real', function () {
    beforeEach(function () {
        Allocation::factory()->forForecast($this->forecast)->forUser($this->ana)->total(2400)->between('2026-11-02', '2026-11-06')->create();
        Allocation::factory()->forForecast($this->forecast)->gap($this->dev)->total(960)->between('2026-12-01', '2026-12-02')->create();
        $this->project = Project::factory()->create(['owner_user_id' => $this->manager->id]);
    });

    it('vincular congela la línea base, copia las asignaciones y añade a las personas como miembros', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager);
        $forecast = $this->forecast->fresh();

        expect($forecast->status)->toBe(ForecastStatus::Linked)
            ->and($forecast->project_id)->toBe($this->project->id)
            ->and($forecast->linked_by)->toBe($this->manager->id)
            ->and($forecast->baseline)->toMatchArray([
                'allocated_minutes' => 3360,
                'planned_start' => '2026-11-02',
                'planned_end' => '2026-12-02',
                'by_month' => ['2026-11' => 2400, '2026-12' => 960],
            ])
            ->and(collect($forecast->baseline['by_department'])->pluck('minutes', 'department_id')->all())->toBe([$this->design->id => 2400, $this->dev->id => 960])
            ->and($forecast->baseline['by_user'])->toBe([['user_id' => $this->ana->id, 'name' => 'Ana', 'department_id' => $this->design->id, 'minutes' => 2400]]);

        $copies = Allocation::query()->where('project_id', $this->project->id)->get();
        expect($copies)->toHaveCount(2)
            ->and($copies->pluck('copied_from_allocation_id')->filter()->count())->toBe(2)
            ->and($this->project->hasMember($this->ana))->toBeTrue();

        // Las del previsto quedan congeladas y la foto no cambia si se toca el real.
        expect(fn () => $this->allocations->update($forecast->allocations->first(), ['minutes' => 60]))->toThrow(ValidationException::class);
        $this->allocations->update($copies->first(), ['minutes' => 60]);
        expect($forecast->fresh()->baseline['allocated_minutes'])->toBe(3360);
    });

    it('sin copiar, el real no recibe asignaciones', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager, copyAllocations: false);

        expect(Allocation::query()->where('project_id', $this->project->id)->count())->toBe(0);
    });

    it('un proyecto solo tiene un previsto, y no se vincula uno archivado ni un previsto perdido', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager);
        $other = ForecastProject::factory()->create();

        expect(fn () => $this->linker->link($other, $this->project, $this->manager))->toThrow(ValidationException::class)
            ->and(fn () => $this->linker->link($other, Project::factory()->archived()->create(), $this->manager))->toThrow(ValidationException::class)
            ->and(fn () => $this->linker->link(ForecastProject::factory()->lost()->create(), Project::factory()->create(), $this->manager))->toThrow(ValidationException::class);
    });

    it('desvincular vuelve a confirmado, borra el vínculo y la foto y deja las copias del real', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager);
        $this->linker->unlink($this->forecast->fresh());
        $forecast = $this->forecast->fresh();

        expect($forecast->status)->toBe(ForecastStatus::Confirmed)
            ->and($forecast->project_id)->toBeNull()
            ->and($forecast->baseline)->toBeNull()
            ->and(Allocation::query()->where('project_id', $this->project->id)->count())->toBe(2);
    });

    it('crear el proyecto real desde el previsto: el alta de siempre, con las personas asignadas como miembros', function () {
        $client = Client::factory()->create();
        $project = $this->linker->createProject($this->forecast, [
            'client_id' => $client->id, 'name' => 'Hotel Mar Azul · Web', 'color' => '#0171FF', 'billing_type' => 'time_and_materials', 'status' => 'planned',
        ], [], $this->manager);

        expect($project->hasMember($this->ana))->toBeTrue()
            ->and($project->owner_user_id)->toBe($this->manager->id)
            ->and($this->forecast->fresh()->project_id)->toBe($project->id);
    });

    it('estimado frente a real: la foto frente a las horas imputadas, por persona, departamento y mes', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager);
        $task = Task::factory()->create(['project_id' => $this->project->id]);
        TimeEntry::factory()->forTask($task)->on('2026-11-03')->minutes(1800)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->forTask($task)->on('2026-10-20')->minutes(600)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->forTask($task)->on('2026-11-02')->minutes(1200)->create(['user_id' => $this->luis->id]);

        $comparison = app(EstimateVsActual::class)->for($this->forecast->fresh());

        // Previsión al cerrar (D-297): lo real más lo que queda asignado en el real (Ana: 2400 − 1800
        // imputados en su rango = 600; el hueco de Desarrollo, 960).
        expect($comparison['totals'])->toBe(['estimated' => 3360, 'actual' => 3600, 'projected' => 5160, 'deviation_percent' => 7.1, 'projected_deviation_percent' => 53.6])
            ->and($comparison['dates'])->toMatchArray(['estimated_start' => '2026-11-02', 'estimated_end' => '2026-12-02', 'actual_start' => '2026-10-20', 'actual_end' => null])
            ->and(collect($comparison['by_department'])->firstWhere('department_id', $this->dev->id))->toMatchArray(['estimated' => 960, 'actual' => 1200, 'projected' => 2160, 'deviation_percent' => 25.0])
            ->and(collect($comparison['by_user'])->firstWhere('user_id', $this->ana->id))->toMatchArray(['estimated' => 2400, 'actual' => 2400, 'projected' => 3000, 'deviation_percent' => 0.0])
            ->and(collect($comparison['by_user'])->firstWhere('user_id', $this->luis->id))->toMatchArray(['estimated' => 0, 'actual' => 1200, 'deviation_percent' => null])
            ->and($comparison['by_month'])->toBe([
                ['month' => '2026-10', 'estimated' => 0, 'actual' => 600, 'remaining' => 0, 'cumulative_estimated' => 0, 'cumulative_actual' => 600, 'cumulative_projected' => 600],
                ['month' => '2026-11', 'estimated' => 2400, 'actual' => 3000, 'remaining' => 600, 'cumulative_estimated' => 2400, 'cumulative_actual' => 3600, 'cumulative_projected' => 4200],
                ['month' => '2026-12', 'estimated' => 960, 'actual' => 0, 'remaining' => 960, 'cumulative_estimated' => 3360, 'cumulative_actual' => 3600, 'cumulative_projected' => 5160],
            ]);
    });

    it('un proyecto completado tiene fin real; uno en curso, el último día asignado o, sin nada asignado, el ritmo de las últimas 4 semanas', function () {
        $this->linker->link($this->forecast, $this->project, $this->manager);
        $task = Task::factory()->create(['project_id' => $this->project->id]);
        TimeEntry::factory()->forTask($task)->on('2026-10-30')->minutes(280)->create(['user_id' => $this->ana->id]);

        // El hueco copiado acaba el 2 de diciembre (D-297).
        $comparison = app(EstimateVsActual::class)->for($this->forecast->fresh());
        expect($comparison['dates']['projected_end'])->toBe('2026-12-02');

        // Sin nada asignado: 280 min en 28 días = 10 min/día; quedan 3.080 → 308 días.
        Allocation::query()->where('project_id', $this->project->id)->delete();
        $comparison = app(EstimateVsActual::class)->for($this->forecast->fresh());
        expect($comparison['dates']['projected_end'])->toBe(CarbonImmutable::parse('2026-11-02')->addDays(308)->toDateString());

        $this->project->update(['status' => ProjectStatus::Completed]);
        $comparison = app(EstimateVsActual::class)->for($this->forecast->fresh()->load('project'));
        expect($comparison['dates'])->toMatchArray(['actual_end' => '2026-10-30', 'projected_end' => null]);
    });

    it('sin vínculo no hay comparación', function () {
        expect(app(EstimateVsActual::class)->for($this->forecast))->toBeNull();
    });
});

$deviation = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/forecast-deviation.json'), true)['cases'];

it('la desviación redondea como la interfaz (caso compartido)', function (int $estimated, int $actual, ?float $percent) {
    expect(EstimateVsActual::deviation($estimated, $actual))->toBe($percent);
})->with(array_map(fn (array $case): array => [$case['estimated'], $case['actual'], $case['percent']], $deviation));
