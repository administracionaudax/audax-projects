<?php

namespace App\Domain\Forecast;

use App\Domain\Time\Capacity;
use App\Domain\Time\CapacityPlan;
use App\Enums\LoadLayer;
use App\Enums\ProjectStatus;
use App\Models\Absence;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
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
 * **Colaboradores externos con asignaciones** (D-300): salen como personas aparte (`collaborator`),
 * con la capacidad de su jornada (WorkSchedule) o sin capacidad si no la tienen (`has_schedule`),
 * y no suman a su departamento ni al total del equipo. Solo los que tienen carga en el periodo.
 *
 * Para la vista (D-301): de cada persona, su avatar, su jornada semanal de hoy y, por columna, los
 * días laborables de ausencia aprobada (`absences`, con el tipo, que el controlador vacía para quien
 * no pueda verlo, D-088); de cada columna, sus festivos (`holidays`).
 *
 * Rendimiento (R7): una consulta de personas, una de colaboradores, una de departamentos, una de
 * asignaciones con sus proyectos y previstos, una de horas imputadas, las de Capacity (horarios,
 * festivos y ausencias) y las de la vista (jornadas, festivos y ausencias), sea cual sea la
 * plantilla o el periodo.
 *
 * @phpstan-type Cell array{capacity: int, real: int, firm: int, tentative: int}
 * @phpstan-type Layers array{real: int, firm: int, tentative: int}
 * @phpstan-type Source array{allocation_id: int, layer: string, user_id: int|null, department_id: int|null, mode: string, project: array{id: int, code: string, name: string, color: string}|null, forecast: array{id: int, name: string, color: string}|null, minutes: list<int>}
 * @phpstan-type Absences array{days: int, partial: bool, type: string|null}
 * @phpstan-type Person array{id: int, name: string, department_id: int|null, avatar: string|null, collaborator: bool, has_schedule: bool, weekly_minutes: int, cells: list<Cell>, absences: list<Absences|null>}
 * @phpstan-type Board array{
 *     period: array{from: string, to: string, granularity: string, today: string, counts_from: string},
 *     buckets: list<array{key: string, from: string, to: string}>,
 *     holidays: list<list<array{date: string, name: string}>>,
 *     people: list<Person>,
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

    /** @var array<string, array<string, string>> festivos leídos en esta petición: «desde-hasta» → fecha → nombre */
    private array $holidayCache = [];

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

        $staff = ForecastPeople::staff()
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereIn('department_id', $departmentIds ?? []))
            ->when($userIds !== null, fn (Builder $query) => $query->whereKey($userIds ?? []))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'avatar_path']);

        // Colaboradores externos con alguna asignación que llegue al periodo (D-300); no en «mi carga».
        $collaborators = $userIds !== null ? new EloquentCollection : ForecastPeople::collaborators()
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereIn('department_id', $departmentIds ?? []))
            ->whereIn('id', Allocation::query()->select('user_id')->whereNotNull('user_id')
                ->where('start_date', '<=', $period->to->toDateString())
                ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', CapacityPlan::date($start))))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'avatar_path']);

        /** @var EloquentCollection<int, User> $people */
        $people = $staff->concat($collaborators->all());
        $collaboratorIds = array_flip(array_map(intval(...), $collaborators->modelKeys()));
        $scheduled = $collaborators->isEmpty() ? [] : array_flip(array_map(intval(...), WorkSchedule::query()
            ->whereIn('user_id', $collaborators->modelKeys())->distinct()->pluck('user_id')->all()));

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
            // Un colaborador sin jornada no tiene capacidad: la celda dice «sin jornada» (D-300).
            $hasCapacity = ! isset($collaboratorIds[$personId]) || isset($scheduled[$personId]);
            foreach ($hasCapacity ? $map : [] as $day => $index) {
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
            // Solo la plantilla: los colaboradores no suman a su departamento (D-300).
            $byDepartment = $staff->groupBy(fn (User $person): int => (int) $person->department_id);
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
            'holidays' => $this->holidays($buckets, $start),
            'people' => $this->people($people, $personCells, $collaboratorIds, $scheduled, $buckets, $start, $today),
            'departments' => $departmentRows,
            'totals' => $totals,
            'sources' => $sources,
            'overdue' => $plan->overdue,
            'unscheduled' => $plan->unscheduled,
        ];
    }

    /**
     * Las personas del tablero con lo que necesita la vista (D-301). Los colaboradores sin carga en
     * el periodo no salen.
     *
     * @param  EloquentCollection<int, User>  $people
     * @param  array<int, array<int, Cell>>  $cells
     * @param  array<int, int>  $collaborators  id → posición
     * @param  array<int, int>  $scheduled  colaboradores con jornada: id → posición
     * @param  list<array{key: string, from: string, to: string}>  $buckets
     * @return list<Person>
     */
    private function people(EloquentCollection $people, array $cells, array $collaborators, array $scheduled, array $buckets, int $start, int $today): array
    {
        $people = $people->filter(fn (User $person): bool => ! isset($collaborators[$person->id])
            || array_sum(array_map(fn (array $cell): int => $cell['real'] + $cell['firm'] + $cell['tentative'], $cells[$person->id])) > 0);

        if ($people->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map(intval(...), $people->modelKeys()));
        $weeks = app(Capacity::class)->weeksOn($ids, CarbonImmutable::parse(CapacityPlan::date($today)));
        $absences = $this->absences($ids, $weeks, $buckets, $start);

        return array_values($people->map(function (User $person) use ($cells, $collaborators, $scheduled, $weeks, $absences): array {
            $hasSchedule = ! isset($collaborators[$person->id]) || isset($scheduled[$person->id]);

            return [
                'id' => $person->id,
                'name' => $person->name,
                'department_id' => $person->department_id,
                'avatar' => $person->avatar_url,
                'collaborator' => isset($collaborators[$person->id]),
                'has_schedule' => $hasSchedule,
                'weekly_minutes' => $hasSchedule ? array_sum($weeks[$person->id] ?? []) : 0,
                'cells' => array_values($cells[$person->id]),
                'absences' => $absences[$person->id] ?? array_fill(0, count($cells[$person->id]), null),
            ];
        })->all());
    }

    /**
     * Días laborables de ausencia aprobada por persona y columna, de hoy en adelante (una consulta).
     * Laborable: con jornada ese día de la semana y sin festivo.
     *
     * @param  list<int>  $ids
     * @param  array<int, list<int>>  $weeks  persona → jornada de hoy (lunes primero)
     * @param  list<array{key: string, from: string, to: string}>  $buckets
     * @return array<int, list<Absences|null>>
     */
    private function absences(array $ids, array $weeks, array $buckets, int $start): array
    {
        if ($buckets === []) {
            return [];
        }

        $last = CapacityPlan::day($buckets[count($buckets) - 1]['to']);

        if ($last < $start) {
            return [];
        }

        $holidays = $this->holidayDays($start, $last);
        $ranges = [];
        foreach ($buckets as $index => $bucket) {
            $ranges[] = [CapacityPlan::day($bucket['from']), CapacityPlan::day($bucket['to']), $index];
        }

        $result = [];
        foreach (Absence::query()->approved()->overlapping(CapacityPlan::date($start), CapacityPlan::date($last))->whereIn('user_id', $ids)
            ->orderBy('start_date')->get(['id', 'user_id', 'type', 'start_date', 'end_date', 'partial_minutes', 'status']) as $absence) {
            $week = $weeks[$absence->user_id] ?? [];
            $from = max($start, CapacityPlan::day($absence->start_date->toDateString()));
            $to = min($last, CapacityPlan::day($absence->end_date->toDateString()));
            $result[$absence->user_id] ??= array_fill(0, count($buckets), null);

            for ($day = $from; $day <= $to; $day++) {
                if (($week[CapacityPlan::weekday($day) - 1] ?? 0) <= 0 || isset($holidays[$day])) {
                    continue;
                }

                foreach ($ranges as [$a, $b, $index]) {
                    if ($day >= $a && $day <= $b) {
                        $current = $result[$absence->user_id][$index] ?? ['days' => 0, 'partial' => false, 'type' => $absence->type->value];
                        $result[$absence->user_id][$index] = [
                            'days' => $current['days'] + 1,
                            'partial' => $current['partial'] || $absence->partial_minutes !== null,
                            'type' => $current['type'],
                        ];

                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Festivos de cada columna, de hoy en adelante.
     *
     * @param  list<array{key: string, from: string, to: string}>  $buckets
     * @return list<list<array{date: string, name: string}>>
     */
    private function holidays(array $buckets, int $start): array
    {
        $result = array_fill(0, count($buckets), []);

        if ($buckets === []) {
            return $result;
        }

        foreach ($this->holidayRows($start, CapacityPlan::day($buckets[count($buckets) - 1]['to'])) as $date => $name) {
            foreach ($buckets as $index => $bucket) {
                if ($date >= $bucket['from'] && $date <= $bucket['to']) {
                    $result[$index][] = ['date' => $date, 'name' => $name];

                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Festivos entre dos días (una consulta por petición).
     *
     * @return array<string, string> fecha → nombre
     */
    private function holidayRows(int $from, int $to): array
    {
        $key = "{$from}-{$to}";

        if (! isset($this->holidayCache[$key])) {
            $this->holidayCache[$key] = [];

            if ($to >= $from) {
                foreach (Holiday::query()->whereBetween('date', [CapacityPlan::date($from), CapacityPlan::date($to)])->orderBy('date')->get(['date', 'name']) as $holiday) {
                    $this->holidayCache[$key][$holiday->date->toDateString()] = $holiday->name;
                }
            }
        }

        return $this->holidayCache[$key];
    }

    /**
     * @return array<int, true>
     */
    private function holidayDays(int $from, int $to): array
    {
        $days = [];
        foreach (array_keys($this->holidayRows($from, $to)) as $date) {
            $days[CapacityPlan::day($date)] = true;
        }

        return $days;
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
