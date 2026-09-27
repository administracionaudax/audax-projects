<?php

namespace App\Domain\Reports;

use App\Domain\Time\Capacity;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Métricas de los informes (SPEC §10). Definiciones (se muestran también en la interfaz):
 * - capacidad: suma de la capacidad diaria de las personas del alcance (Capacity; F3 añadirá
 *   festivos y ausencias). Cuenta desde el alta de cada persona y, si está desactivada, hasta su
 *   última entrada del periodo,
 * - imputadas: suma de minutes; facturables: las de is_billable,
 * - ocupación = imputadas / capacidad; facturabilidad = facturables / imputadas;
 *   productividad facturable = facturables / capacidad,
 * - precisión de estimación = estimadas / reales en las tareas hoja completadas en el periodo, con
 *   la desviación (reales − estimadas) / estimadas,
 * - ingreso, coste y rentabilidad: RevenueCalculator (D-043), solo con view-financials.
 */
final class Metrics
{
    /**
     * Capacidades ya calculadas en esta instancia (el resumen y la serie de un mismo alcance piden
     * la misma), por persona que mira y filtros. Metrics se resuelve por petición o por tarea: la
     * memoria no sobrevive a otra escritura. Con un tope para no crecer sin límite.
     *
     * @var array<string, array<int, array<string, int>>>
     */
    private array $capacityMemo = [];

    private const int CAPACITY_MEMO_SIZE = 8;

    public function __construct(
        private readonly RevenueCalculator $revenue,
        private readonly Capacity $capacity,
    ) {}

    /**
     * @return array{capacity_minutes: int, logged_minutes: int, billable_minutes: int, in_bank_minutes: int,
     *     overage_minutes: int, occupancy: float|null, billability: float|null, billable_productivity: float|null,
     *     estimation: array{tasks: int, estimated_minutes: int, actual_minutes: int, accuracy: float|null, deviation: float|null},
     *     income: string|null, cost: string|null, margin: string|null, margin_pct: float|null}
     */
    public function summary(ReportScope $scope): array
    {
        $totals = (clone $scope->entries())->toBase()->selectRaw(
            'COALESCE(SUM(time_entries.minutes), 0) as logged,
             COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END), 0) as billable,
             COALESCE(SUM(time_entries.overage_minutes), 0) as overage'
        )->first();

        $logged = (int) ($totals->logged ?? 0);
        $billable = (int) ($totals->billable ?? 0);
        $overage = (int) ($totals->overage ?? 0);
        $capacity = array_sum($this->capacityByDate($scope));

        $summary = [
            'capacity_minutes' => $capacity,
            'logged_minutes' => $logged,
            'billable_minutes' => $billable,
            'in_bank_minutes' => $logged - $overage,
            'overage_minutes' => $overage,
            'occupancy' => self::ratio($logged, $capacity),
            'billability' => self::ratio($billable, $logged),
            'billable_productivity' => self::ratio($billable, $capacity),
            'estimation' => $this->estimation($scope),
            'income' => null,
            'cost' => null,
            'margin' => null,
            'margin_pct' => null,
        ];

        if ($scope->canSeeFinancials()) {
            $money = $this->revenue->compute($scope->entries())['all'] ?? ['income' => '0.00', 'cost' => '0.00'];
            $margin = Money::round(Money::sub($money['income'], $money['cost']));
            $summary['income'] = $money['income'];
            $summary['cost'] = $money['cost'];
            $summary['margin'] = $margin;
            $summary['margin_pct'] = Money::isZero($money['income']) ? null : round((float) Money::div($margin, $money['income']), 4);
        }

        return $summary;
    }

    /**
     * Capacidad por fecha (Y-m-d) del alcance, con una sola consulta de horarios.
     *
     * @return array<string, int>
     */
    public function capacityByDate(ReportScope $scope): array
    {
        $byDate = [];
        foreach ($this->capacityByPerson($scope) as $days) {
            foreach ($days as $date => $minutes) {
                $byDate[$date] = ($byDate[$date] ?? 0) + $minutes;
            }
        }

        return $byDate;
    }

    /**
     * Capacidad de cada persona del alcance por fecha (id → Y-m-d → minutos), con las mismas reglas
     * que capacityByDate (que es su suma): desde el alta o el primer horario y, si está
     * desactivada, hasta su última entrada del periodo. Las personas sin capacidad en el periodo no
     * salen. Añadido por R1 (miembros del departamento).
     *
     * @return array<int, array<string, int>>
     */
    public function capacityByPerson(ReportScope $scope): array
    {
        $key = $scope->viewer->id.':'.$scope->filters->cacheKey();

        if (! array_key_exists($key, $this->capacityMemo)) {
            if (count($this->capacityMemo) >= self::CAPACITY_MEMO_SIZE) {
                array_shift($this->capacityMemo);
            }

            $this->capacityMemo[$key] = $this->computeCapacityByPerson($scope);
        }

        return $this->capacityMemo[$key];
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function computeCapacityByPerson(ReportScope $scope): array
    {
        $f = $scope->filters;
        $people = $scope->people();

        if ($people->isEmpty()) {
            return [];
        }

        $firstSchedule = WorkSchedule::query()->whereIn('user_id', $people->modelKeys())
            ->groupBy('user_id')->selectRaw('user_id, MIN(valid_from) as first')->toBase()->pluck('first', 'user_id');
        $lastEntry = TimeEntry::query()->whereIn('user_id', $people->where('is_active', false)->modelKeys())
            ->whereBetween('date', [$f->from->toDateString(), $f->to->toDateString()])
            ->groupBy('user_id')->selectRaw('user_id, MAX(date) as last')->toBase()->pluck('last', 'user_id');

        $ranges = [];
        foreach ($people as $person) {
            $joined = $person->created_at !== null ? LocalTime::dateOf($person->created_at) : $f->from->toDateString();
            $first = $firstSchedule[$person->id] ?? null;
            $start = max($f->from->toDateString(), $first !== null ? min($joined, substr((string) $first, 0, 10)) : $joined);
            $end = $person->is_active ? $f->to->toDateString() : (isset($lastEntry[$person->id]) ? substr((string) $lastEntry[$person->id], 0, 10) : null);

            if ($end === null || $start > $end) {
                continue;
            }

            $ranges[] = ['user_id' => $person->id, 'from' => CarbonImmutable::parse($start), 'to' => CarbonImmutable::parse($end)];
        }

        $byPerson = [];
        foreach ($this->capacity->forRanges($ranges) as $index => $days) {
            $byPerson[$ranges[$index]['user_id']] = $days;
        }

        return $byPerson;
    }

    /**
     * Evolución por día, semana o mes: imputadas, facturables, capacidad y (con permiso) ingreso.
     *
     * @return list<array{bucket: string, logged_minutes: int, billable_minutes: int, capacity_minutes: int, income: string|null}>
     */
    public function series(ReportScope $scope, Dimension $bucket): array
    {
        if (! $bucket->isTime()) {
            throw new \InvalidArgumentException('La serie necesita una dimensión de tiempo.');
        }

        $f = $scope->filters;
        $expression = $bucket->expression();
        $rows = (clone $scope->entries())->toBase()
            ->selectRaw($expression.' as bucket, SUM(time_entries.minutes) as logged,
                SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END) as billable')
            ->groupByRaw($expression)
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->bucket, 0, 10));

        $income = $scope->canSeeFinancials() ? $this->revenue->compute($scope->entries(), $bucket) : [];

        $capacity = [];
        foreach ($this->capacityByDate($scope) as $date => $minutes) {
            $key = self::bucketOf($bucket, $date);
            $capacity[$key] = ($capacity[$key] ?? 0) + $minutes;
        }

        $series = [];
        foreach (CarbonPeriod::create($f->from, $f->to) as $day) {
            $key = self::bucketOf($bucket, $day->toDateString());
            if (isset($series[$key])) {
                continue;
            }
            $row = $rows->get($key);
            $series[$key] = [
                'bucket' => $key,
                'logged_minutes' => (int) ($row->logged ?? 0),
                'billable_minutes' => (int) ($row->billable ?? 0),
                'capacity_minutes' => $capacity[$key] ?? 0,
                'income' => $scope->canSeeFinancials() ? ($income[$key]['income'] ?? '0.00') : null,
            ];
        }

        return array_values($series);
    }

    /**
     * Desglose por una dimensión, ordenado por horas imputadas (desc).
     *
     * @return list<array{key: string|null, name: string, color: string|null, logged_minutes: int, billable_minutes: int,
     *     in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null}>
     */
    public function breakdown(ReportScope $scope, Dimension $dimension, ?int $limit = null): array
    {
        $query = clone $scope->entries();
        $dimension->join($query);
        $expression = $dimension->expression();

        $rows = $query->toBase()
            ->selectRaw($expression.' as group_key, SUM(time_entries.minutes) as logged,
                SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END) as billable,
                SUM(time_entries.overage_minutes) as overage')
            ->groupByRaw($expression)
            ->orderByDesc('logged')
            ->when($limit !== null, fn ($q) => $q->limit((int) $limit))
            ->get();

        $money = $scope->canSeeFinancials() ? $this->revenue->compute($scope->entries(), $dimension) : [];
        $labels = $this->labels($dimension, array_values($rows->pluck('group_key')->filter(fn ($key): bool => $key !== null)->all()));

        return array_values($rows->map(function (object $row) use ($dimension, $money, $labels, $scope): array {
            $key = $row->group_key === null ? null : (string) $row->group_key;
            $label = $key === null ? ['name' => self::emptyLabel($dimension), 'color' => null] : ($labels[$key] ?? ['name' => $key, 'color' => null]);
            $logged = (int) $row->logged;

            return [
                'key' => $key,
                'name' => $label['name'],
                'color' => $label['color'],
                'logged_minutes' => $logged,
                'billable_minutes' => (int) $row->billable,
                'in_bank_minutes' => $logged - (int) $row->overage,
                'overage_minutes' => (int) $row->overage,
                'income' => $scope->canSeeFinancials() ? ($money[$key ?? '']['income'] ?? '0.00') : null,
                'cost' => $scope->canSeeFinancials() ? ($money[$key ?? '']['cost'] ?? '0.00') : null,
            ];
        })->all());
    }

    /**
     * Precisión de estimación (SPEC §10): tareas hoja completadas en el periodo, con estimación, de
     * los proyectos y personas del alcance. Reales = todas sus horas (de cualquier fecha).
     *
     * @return array{tasks: int, estimated_minutes: int, actual_minutes: int, accuracy: float|null, deviation: float|null}
     */
    public function estimation(ReportScope $scope): array
    {
        $f = $scope->filters;
        $zone = LocalTime::timezone();
        $start = CarbonImmutable::parse($f->from->toDateString(), $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($f->to->toDateString(), $zone)->endOfDay()->utc();

        $tasks = Task::query()
            ->whereBetween('completed_at', [$start, $end])
            ->where('is_milestone', false)
            ->where('estimated_minutes', '>', 0)
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('tasks as children')
                ->whereColumn('children.parent_task_id', 'tasks.id')->whereNull('children.deleted_at'))
            ->when($f->projectIds !== [], fn (Builder $q) => $q->whereIn('project_id', $f->projectIds))
            ->when($f->clientIds !== [], fn (Builder $q) => $q->whereIn('project_id', Project::query()->withTrashed()->select('id')->whereIn('client_id', $f->clientIds)))
            ->when($f->bankIds !== [], fn (Builder $q) => $q->whereIn('hour_bank_id', $f->bankIds))
            ->when($f->taskTypeIds !== [], fn (Builder $q) => $q->whereIn('task_type_id', $f->taskTypeIds))
            ->when(! $scope->viewer->isAdmin() || $f->userIds !== [] || $f->departmentIds !== [],
                fn (Builder $q) => $q->whereIn('assignee_user_id', $scope->people()->modelKeys()));

        $estimated = (int) (clone $tasks)->sum('estimated_minutes');
        $count = (clone $tasks)->count();
        $actual = (int) TimeEntry::query()->whereIn('task_id', (clone $tasks)->select('tasks.id'))->sum('minutes');

        return [
            'tasks' => $count,
            'estimated_minutes' => $estimated,
            'actual_minutes' => $actual,
            'accuracy' => self::ratio($estimated, $actual),
            'deviation' => $estimated > 0 ? round(($actual - $estimated) / $estimated, 4) : null,
        ];
    }

    public static function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : null;
    }

    public static function bucketOf(Dimension $bucket, string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($bucket) {
            Dimension::Week => $day->startOfWeek()->toDateString(),
            Dimension::Month => $day->startOfMonth()->toDateString(),
            default => $day->toDateString(),
        };
    }

    public static function emptyLabel(Dimension $dimension): string
    {
        return match ($dimension) {
            Dimension::Department => 'Sin departamento',
            Dimension::Client => 'Interno (sin cliente)',
            Dimension::HourBank => 'Sin bolsa',
            Dimension::TaskType => 'Sin tipo',
            default => '—',
        };
    }

    /**
     * Nombres (y colores) de las claves de un desglose, con una consulta.
     *
     * @param  list<mixed>  $keys
     * @return array<string, array{name: string, color: string|null}>
     */
    public function labels(Dimension $dimension, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $keys)));

        $rows = match ($dimension) {
            Dimension::Person => User::query()->whereIn('id', $ids)->get(['id', 'name'])->map(fn (User $u) => [$u->id, $u->name, null]),
            Dimension::Department => Department::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'color'])->map(fn (Department $d) => [$d->id, $d->name, $d->color]),
            Dimension::Client => Client::withTrashed()->whereIn('id', $ids)->get(['id', 'name'])->map(fn (Client $c) => [$c->id, $c->name, null]),
            Dimension::Project => Project::withTrashed()->whereIn('id', $ids)->get(['id', 'code', 'name', 'color'])->map(fn (Project $p) => [$p->id, $p->code.' · '.$p->name, $p->color]),
            Dimension::HourBank => HourBank::withTrashed()->with(['project' => fn ($q) => $q->withTrashed()->select(['id', 'code'])])->whereIn('id', $ids)->get(['id', 'name', 'project_id'])->map(fn (HourBank $b) => [$b->id, $b->project->code.' · '.$b->name, null]),
            Dimension::TaskType => TaskType::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'color'])->map(fn (TaskType $t) => [$t->id, $t->name, $t->color]),
            Dimension::Task => Task::withTrashed()->whereIn('id', $ids)->get(['id', 'title'])->map(fn (Task $t) => [$t->id, $t->title, null]),
            default => collect($keys)->map(fn ($key) => [$key, (string) $key, null]),
        };

        $labels = [];
        foreach ($rows as [$id, $name, $color]) {
            $labels[(string) $id] = ['name' => (string) $name, 'color' => $color];
        }

        return $labels;
    }
}
