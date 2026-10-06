<?php

namespace App\Domain\Forecast;

use App\Domain\Time\CapacityPlan;
use App\Enums\LoadLayer;
use App\Enums\ProjectStatus;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Carga de la previsión (docs/PLAN-CARGAS.md §6.3 a §6.5 con las respuestas P5, P6 y P7; D-283):
 * capacidad frente a asignado, por persona y por departamento, por semana o por mes, en tres capas:
 *
 * - **real**: asignaciones de proyectos reales planificados o activos (no en pausa, completados,
 *   archivados ni borrados),
 * - **firm**: asignaciones de previstos «seguros» abiertos o confirmados,
 * - **tentative**: asignaciones de previstos «posibles» abiertos.
 *
 * Solo asignaciones (P6 y P7): ni las horas estimadas de las tareas, ni las bolsas, ni los fees
 * cuentan (para que cuenten, se les crea una asignación). Los previstos perdidos o vinculados no
 * cuentan nunca (el vinculado ya está en su real).
 *
 * Cuenta de hoy en adelante: los días pasados del periodo no tienen ni carga ni capacidad, para que
 * la ocupación del mes en curso no salga baja (D-285). La capacidad de un departamento es la suma de
 * sus personas de plantilla activas; sus huecos (asignaciones sin persona) suman a su carga y salen
 * aparte en `gaps`.
 *
 * Rendimiento (R7): una consulta de personas, una de departamentos, una de asignaciones con sus
 * proyectos y previstos, una de horas imputadas y las de Capacity (horarios, festivos y ausencias),
 * sea cual sea la plantilla o el periodo.
 *
 * @phpstan-type Cell array{capacity: int, real: int, firm: int, tentative: int}
 * @phpstan-type Layers array{real: int, firm: int, tentative: int}
 * @phpstan-type Source array{allocation_id: int, layer: string, user_id: int|null, department_id: int|null, mode: string, project: array{id: int, code: string, name: string, color: string}|null, forecast: array{id: int, name: string, color: string}|null, minutes: list<int>}
 * @phpstan-type Board array{
 *     period: array{from: string, to: string, granularity: string, today: string, counts_from: string},
 *     buckets: list<array{key: string, from: string, to: string}>,
 *     people: list<array{id: int, name: string, department_id: int|null, cells: list<Cell>}>,
 *     departments: list<array{id: int|null, name: string|null, color: string|null, people: int, cells: list<Cell>, gaps: list<Layers>}>,
 *     totals: list<Cell>,
 *     sources: list<Source>,
 *     overdue: list<int>,
 *     unscheduled: list<int>
 * }
 */
final class LoadCombiner
{
    /** @var Cell */
    private const array EMPTY_CELL = ['capacity' => 0, 'real' => 0, 'firm' => 0, 'tentative' => 0];

    public function __construct(private readonly AllocationPlanner $planner) {}

    /**
     * @param  array{department_ids?: list<int>, user_ids?: list<int>, exclude_forecast_ids?: list<int>, only_forecast_ids?: list<int>}  $options
     *                                                                                                                                             - department_ids: solo esas personas, esos departamentos y sus huecos,
     *                                                                                                                                             - user_ids: solo esas personas (p. ej. «mi carga»); sin departamentos,
     *                                                                                                                                             - exclude_forecast_ids: sin esos previstos (el «sin» del impacto),
     *                                                                                                                                             - only_forecast_ids: solo las asignaciones de esos previstos (el impacto),
     *                                                                                                                                             aunque no cuenten (perdidos o vinculados no; abiertos o confirmados sí).
     * @return Board
     */
    public function board(ForecastPeriod $period, array $options = []): array
    {
        $today = CapacityPlan::day(LocalTime::todayString());
        $first = CapacityPlan::day($period->from->toDateString());
        $last = CapacityPlan::day($period->to->toDateString());
        $start = max($first, $today);

        $departmentIds = $options['department_ids'] ?? null;
        $userIds = $options['user_ids'] ?? null;

        $people = ForecastPeople::staff()
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereIn('department_id', $departmentIds ?? []))
            ->when($userIds !== null, fn (Builder $query) => $query->whereKey($userIds ?? []))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id']);

        $departments = $userIds !== null ? new EloquentCollection : Department::query()
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereKey($departmentIds ?? []))
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $peopleIds = array_values(array_map(intval(...), $people->modelKeys()));
        $departmentKeys = array_values(array_map(intval(...), $departments->modelKeys()));
        $allocations = $this->allocations($peopleIds, $departmentKeys, $period, $start, $today, $options);
        $members = CapacityCalendar::membersOf($departmentKeys);

        $plan = $this->planner->plan(
            $allocations,
            $last,
            $today,
            AllocationPlanner::logged($allocations),
            ['users' => $peopleIds, 'departments' => $departmentKeys, 'from' => $start, 'to' => $last, 'members' => $members],
        );

        $buckets = $period->buckets();
        $count = count($buckets);
        $map = $period->bucketOfDays($start);
        $empty = array_fill(0, $count, self::EMPTY_CELL);

        /** @var array<int, list<Cell>> $personCells */
        $personCells = [];
        foreach ($peopleIds as $personId) {
            $capacity = array_fill(0, $count, 0);
            foreach ($map as $day => $index) {
                $capacity[$index] += $plan->capacity->forUser($personId, $day);
            }
            $personCells[$personId] = array_map(fn (int $minutes): array => [...self::EMPTY_CELL, 'capacity' => $minutes], $capacity);
        }

        /** @var array<int, list<Cell>> $gapCells huecos (sin capacidad) */
        $gapCells = [];
        $sources = [];

        foreach ($allocations as $allocation) {
            $layer = $this->layer($allocation);
            $userId = $allocation->user_id;
            $departmentId = $allocation->department_id;
            $minutes = array_fill(0, $count, 0);

            foreach ($plan->days[$allocation->id] ?? [] as $day => $value) {
                $index = $map[$day] ?? null;

                if ($index !== null) {
                    $minutes[$index] += $value;
                }
            }

            foreach ($minutes as $index => $value) {
                if ($value === 0) {
                    continue;
                }

                if ($userId !== null) {
                    if (isset($personCells[$userId][$index])) {
                        $personCells[$userId][$index] = self::addCell($personCells[$userId][$index], self::layerCell($layer, $value));
                    }
                } elseif ($departmentId !== null) {
                    $gapCells[$departmentId] ??= $empty;
                    $gapCells[$departmentId][$index] = self::addCell($gapCells[$departmentId][$index], self::layerCell($layer, $value));
                }
            }

            if (array_sum($minutes) > 0) {
                $sources[] = $this->source($allocation, $layer->value, array_values($minutes));
            }
        }

        $departmentRows = [];
        $totals = $empty;

        if ($userIds === null) {
            $byDepartment = $people->groupBy(fn (User $person): int => (int) $person->department_id);
            $rows = array_values($departments->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->all());

            // Sin departamento: solo si hay alguien sin él (no suma huecos: un hueco siempre es de uno).
            if ($departmentIds === null && $byDepartment->has(0)) {
                $rows[] = ['id' => null, 'name' => null, 'color' => null];
            }

            foreach ($rows as $row) {
                $cells = $empty;
                $members = $byDepartment->get((int) $row['id']) ?? new EloquentCollection;

                foreach ($members as $member) {
                    foreach ($personCells[$member->id] as $index => $cell) {
                        $cells[$index] = self::addCell($cells[$index], $cell);
                    }
                }

                $gaps = $row['id'] === null ? $empty : ($gapCells[$row['id']] ?? $empty);
                foreach ($gaps as $index => $gap) {
                    $cells[$index] = self::addCell($cells[$index], $gap);
                }

                foreach ($cells as $index => $cell) {
                    $totals[$index] = self::addCell($totals[$index], $cell);
                }

                $departmentRows[] = [...$row, 'people' => $members->count(), 'cells' => $cells, 'gaps' => array_map(fn (array $gap): array => ['real' => $gap['real'], 'firm' => $gap['firm'], 'tentative' => $gap['tentative']], $gaps)];
            }
        } else {
            foreach ($personCells as $cells) {
                foreach ($cells as $index => $cell) {
                    $totals[$index] = self::addCell($totals[$index], $cell);
                }
            }
        }

        return [
            'period' => [
                'from' => $period->from->toDateString(),
                'to' => $period->to->toDateString(),
                'granularity' => $period->granularity,
                'today' => CapacityPlan::date($today),
                'counts_from' => CapacityPlan::date($start),
            ],
            'buckets' => $buckets,
            'people' => array_values($people->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'department_id' => $person->department_id,
                'cells' => array_values($personCells[$person->id]),
            ])->all()),
            'departments' => $departmentRows,
            'totals' => $totals,
            'sources' => $sources,
            'overdue' => $plan->overdue,
            'unscheduled' => $plan->unscheduled,
        ];
    }

    /**
     * La capa de una asignación: real si es de un proyecto; si no, la seguridad de su previsto.
     */
    public function layer(Allocation $allocation): LoadLayer
    {
        if ($allocation->project_id !== null) {
            return LoadLayer::Real;
        }

        return $allocation->forecastProject?->confidence->layer() ?? LoadLayer::Tentative;
    }

    /**
     * Asignaciones que cuentan en el periodo (o vencidas con restante, que van a hoy).
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $departmentIds
     * @param  array{exclude_forecast_ids?: list<int>, only_forecast_ids?: list<int>, user_ids?: list<int>}  $options
     * @return list<Allocation>
     */
    private function allocations(array $userIds, array $departmentIds, ForecastPeriod $period, int $start, int $today, array $options): array
    {
        $startDate = CapacityPlan::date($start);
        $only = $options['only_forecast_ids'] ?? null;
        $exclude = $options['exclude_forecast_ids'] ?? [];

        $counting = ForecastProject::query()->select('id')->counting()
            ->when($exclude !== [], fn (Builder $query) => $query->whereNotIn('id', $exclude))
            ->when($only !== null, fn (Builder $query) => $query->whereKey($only ?? []));

        /** @var list<Allocation> */
        return Allocation::query()
            ->where('start_date', '<=', $period->to->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('end_date')
                ->orWhere('end_date', '>=', $startDate)
                // Vencidas: el restante de una persona en un proyecto real va a hoy.
                ->orWhere(fn (Builder $overdue) => $overdue->where('mode', 'total')->whereNotNull('project_id')->whereNotNull('user_id')->where('end_date', '<', CapacityPlan::date($today))))
            ->where(fn (Builder $query) => $only !== null
                ? $query->whereIn('forecast_project_id', $counting)
                : $query->whereIn('project_id', Project::query()->select('id')->whereIn('status', [ProjectStatus::Planned->value, ProjectStatus::Active->value]))
                    ->orWhereIn('forecast_project_id', $counting))
            ->where(fn (Builder $query) => $query
                ->whereIn('user_id', $userIds)
                ->when(! isset($options['user_ids']), fn (Builder $gaps) => $gaps->orWhereIn('department_id', $departmentIds)))
            ->with([
                'project:id,name,code,color,status',
                'forecastProject:id,name,color,confidence,status',
            ])
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Una celda con $minutes en la capa $layer y nada más.
     *
     * @return Cell
     */
    private static function layerCell(LoadLayer $layer, int $minutes): array
    {
        return [
            'capacity' => 0,
            'real' => $layer === LoadLayer::Real ? $minutes : 0,
            'firm' => $layer === LoadLayer::Firm ? $minutes : 0,
            'tentative' => $layer === LoadLayer::Tentative ? $minutes : 0,
        ];
    }

    /**
     * @param  Cell  $a
     * @param  Cell  $b
     * @return Cell
     */
    private static function addCell(array $a, array $b): array
    {
        return [
            'capacity' => $a['capacity'] + $b['capacity'],
            'real' => $a['real'] + $b['real'],
            'firm' => $a['firm'] + $b['firm'],
            'tentative' => $a['tentative'] + $b['tentative'],
        ];
    }

    /**
     * @param  list<int>  $minutes
     * @return Source
     */
    private function source(Allocation $allocation, string $layer, array $minutes): array
    {
        return [
            'allocation_id' => $allocation->id,
            'layer' => $layer,
            'user_id' => $allocation->user_id,
            'department_id' => $allocation->department_id,
            'mode' => $allocation->mode->value,
            'project' => $allocation->project === null ? null : [
                'id' => $allocation->project->id,
                'code' => $allocation->project->code,
                'name' => $allocation->project->name,
                'color' => $allocation->project->color,
            ],
            'forecast' => $allocation->forecastProject === null ? null : [
                'id' => $allocation->forecastProject->id,
                'name' => $allocation->forecastProject->name,
                'color' => $allocation->forecastProject->color,
            ],
            'minutes' => $minutes,
        ];
    }
}
