<?php

namespace App\Domain\Forecast;

use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Impacto «sin / con» un proyecto previsto (docs/PLAN-CARGAS.md §5.2 paso 3 y §5.3, D-285): por
 * mes, la capacidad y la carga de cada departamento y de cada persona que toca el previsto, sin él
 * (todas las capas: real, seguro y posible, «la pregunta incómoda primero») y con él. Solo de los
 * previstos que cuentan (abiertos o confirmados); de hoy en adelante y como mucho 12 meses.
 *
 * @phpstan-type ImpactCell array{capacity: int, without: int, with: int}
 * @phpstan-type Impact array{
 *     buckets: list<array{key: string, from: string, to: string}>,
 *     layer: string,
 *     departments: list<array{id: int|null, name: string|null, color: string|null, cells: list<ImpactCell>}>,
 *     people: list<array{id: int, name: string, department_id: int|null, cells: list<ImpactCell>}>
 * }
 */
final class ForecastImpact
{
    /** Meses del impacto de un previsto sin fechas de fin. */
    public const int OPEN_MONTHS = 3;

    public function __construct(private readonly LoadCombiner $combiner) {}

    /**
     * @return Impact|null null si el previsto no cuenta (perdido o vinculado)
     */
    public function for(ForecastProject $forecast): ?array
    {
        $layer = $forecast->layer();

        if ($layer === null) {
            return null;
        }

        $allocations = $forecast->relationLoaded('allocations')
            ? $forecast->allocations
            : $forecast->allocations()->get(['id', 'forecast_project_id', 'user_id', 'department_id', 'start_date', 'end_date']);
        $today = LocalTime::today();

        if ($allocations->isEmpty()) {
            return ['buckets' => [], 'layer' => $layer->value, 'departments' => [], 'people' => []];
        }

        $start = $allocations->min(fn (Allocation $allocation): string => $allocation->start_date->toDateString());
        $end = $allocations->max(fn (Allocation $allocation): string => $allocation->end_date?->toDateString()
            ?? $forecast->end_date?->toDateString()
            ?? CarbonImmutable::parse(max((string) $start, $today->toDateString()))->addMonthsNoOverflow(self::OPEN_MONTHS)->toDateString());

        $from = CarbonImmutable::parse(max((string) $start, $today->toDateString()));
        $to = CarbonImmutable::parse((string) $end);

        if ($to < $from) {
            return ['buckets' => [], 'layer' => $layer->value, 'departments' => [], 'people' => []];
        }

        $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;
        $period = ForecastPeriod::make($from, $months, ForecastPeriod::MONTH);

        $without = $this->combiner->board($period, ['exclude_forecast_ids' => [$forecast->id]]);
        $only = $this->combiner->board($period, ['only_forecast_ids' => [$forecast->id]]);

        $userIds = $allocations->pluck('user_id')->filter()->unique()->all();
        $people = array_values(array_filter($without['people'], fn (array $person): bool => in_array($person['id'], $userIds, true)));
        $onlyPeople = collect($only['people'])->keyBy('id');

        $departmentIds = $allocations->pluck('department_id')->filter()
            ->merge(array_column($people, 'department_id'))
            ->filter()->unique()->all();
        $departments = array_values(array_filter($without['departments'], fn (array $department): bool => in_array($department['id'], $departmentIds, true)));
        $onlyDepartments = collect($only['departments'])->keyBy('id');

        return [
            'buckets' => $without['buckets'],
            'layer' => $layer->value,
            'departments' => array_map(fn (array $department): array => [
                'id' => $department['id'],
                'name' => $department['name'],
                'color' => $department['color'],
                'cells' => self::cells($department['cells'], $onlyDepartments->get($department['id'])['cells'] ?? []),
            ], $departments),
            'people' => array_map(fn (array $person): array => [
                'id' => $person['id'],
                'name' => $person['name'],
                'department_id' => $person['department_id'],
                'cells' => self::cells($person['cells'], $onlyPeople->get($person['id'])['cells'] ?? []),
            ], $people),
        ];
    }

    /**
     * @param  list<array{capacity: int, real: int, firm: int, tentative: int}>  $without
     * @param  list<array{capacity: int, real: int, firm: int, tentative: int}>  $only
     * @return list<ImpactCell>
     */
    private static function cells(array $without, array $only): array
    {
        return array_map(function (array $cell, int $index) use ($only): array {
            $load = $cell['real'] + $cell['firm'] + $cell['tentative'];
            $own = isset($only[$index]) ? $only[$index]['real'] + $only[$index]['firm'] + $only[$index]['tentative'] : 0;

            return ['capacity' => $cell['capacity'], 'without' => $load, 'with' => $load + $own];
        }, $without, array_keys($without));
    }
}
