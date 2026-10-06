<?php

namespace App\Domain\Reports\Project;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\KeysetPages;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Secciones del informe completo de un proyecto (D-240) que no estaban en la página: horas del
 * periodo por tarea, la matriz tarea × persona, la evolución por mes, las bolsas del proyecto y el
 * listado de entradas. Todo sale de ReportScope::entries() (D-044: quien mira ve lo que puede ver,
 * con los filtros de la URL), así que la versión para el cliente (D-241) usa las mismas piezas con
 * un alcance acotado a los estados que ve el cliente. Un número fijo de consultas: sin N+1.
 *
 * @phpstan-type MonthRow array{month: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null}
 * @phpstan-type Matrix array{tasks: list<array{id: int, title: string, total: int}>, people: list<array{id: int, name: string, total: int}>, cells: array<int, array<int, int>>}
 * @phpstan-type BankRow array{id: int, name: string, status: string, start_date: string, end_date: string|null, total_minutes: int,
 *     consumed_minutes: int, in_bank_minutes: int, overage_minutes: int, remaining_minutes: int, consumed_ratio: float,
 *     period_minutes: int, price_amount: string|null, hourly_rate: string|null}
 * @phpstan-type EntryRow array{id: int, date: string, user_id: int, person: string, task: string, parent: string|null,
 *     start: string|null, end: string|null, minutes: int, description: string, billable: bool, status: string}
 */
final class ProjectReportSections
{
    /** Entradas por bloque del listado (una consulta por bloque). */
    public const int CHUNK = 1000;

    public function __construct(private readonly Metrics $metrics) {}

    /**
     * Minutos del periodo por tarea (id de la tarea en la que se imputaron). Una consulta.
     *
     * @return array<int, int>
     */
    public function periodByTask(ReportScope $scope): array
    {
        $minutes = [];
        $rows = (clone $scope->entries())->toBase()
            ->selectRaw('time_entries.task_id as task_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.task_id')
            ->get();

        foreach ($rows as $row) {
            $minutes[(int) $row->task_id] = (int) $row->minutes;
        }

        return $minutes;
    }

    /**
     * Horas del periodo por tarea principal y persona: las de una subtarea suman en su tarea principal
     * (SPEC §6, Dimension::Task). Filas y columnas por horas (desc). Tres consultas.
     *
     * @return Matrix
     */
    public function matrix(ReportScope $scope): array
    {
        $query = clone $scope->entries();
        Dimension::Task->join($query);
        $task = Dimension::Task->expression();

        $rows = $query->toBase()
            ->selectRaw($task.' as task_key, time_entries.user_id as user_id, SUM(time_entries.minutes) as minutes')
            ->groupByRaw($task.', time_entries.user_id')
            ->get();

        /** @var array<int, array<int, int>> $cells */
        $cells = [];
        $taskTotals = [];
        $personTotals = [];
        foreach ($rows as $row) {
            $taskId = (int) $row->task_key;
            $userId = (int) $row->user_id;
            $minutes = (int) $row->minutes;
            $cells[$taskId][$userId] = ($cells[$taskId][$userId] ?? 0) + $minutes;
            $taskTotals[$taskId] = ($taskTotals[$taskId] ?? 0) + $minutes;
            $personTotals[$userId] = ($personTotals[$userId] ?? 0) + $minutes;
        }

        $titles = $taskTotals === [] ? [] : Task::query()->withTrashed()->whereKey(array_keys($taskTotals))->pluck('title', 'id')->all();
        $names = $personTotals === [] ? [] : User::query()->whereKey(array_keys($personTotals))->pluck('name', 'id')->all();

        $tasks = [];
        foreach ($taskTotals as $id => $total) {
            $tasks[] = ['id' => $id, 'title' => (string) ($titles[$id] ?? '—'), 'total' => $total];
        }
        usort($tasks, fn (array $a, array $b): int => [$b['total'], $a['title']] <=> [$a['total'], $b['title']]);

        $people = [];
        foreach ($personTotals as $id => $total) {
            $people[] = ['id' => $id, 'name' => (string) ($names[$id] ?? '—'), 'total' => $total];
        }
        usort($people, fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return ['tasks' => $tasks, 'people' => $people, 'cells' => $cells];
    }

    /**
     * Meses del periodo sin huecos (primer día del mes), con lo imputado, facturable, dentro de
     * bolsa, exceso y, con datos económicos, ingreso y coste. Metrics::breakdown por mes.
     *
     * @return list<MonthRow>
     */
    public function monthly(ReportScope $scope): array
    {
        $byMonth = [];
        foreach ($this->metrics->breakdown($scope, Dimension::Month) as $row) {
            $byMonth[substr((string) $row['key'], 0, 10)] = $row;
        }

        return self::months($scope->filters, $byMonth);
    }

    /**
     * @param  array<string, array{logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null}>  $byMonth
     * @return list<MonthRow>
     */
    public static function months(ReportFilters $filters, array $byMonth): array
    {
        $months = [];
        for ($month = $filters->from->startOfMonth(); $month->lessThanOrEqualTo($filters->to); $month = $month->addMonthNoOverflow()) {
            $key = $month->toDateString();
            $row = $byMonth[$key] ?? null;
            $months[] = [
                'month' => $key,
                'logged_minutes' => $row['logged_minutes'] ?? 0,
                'billable_minutes' => $row['billable_minutes'] ?? 0,
                'in_bank_minutes' => $row['in_bank_minutes'] ?? 0,
                'overage_minutes' => $row['overage_minutes'] ?? 0,
                'income' => $row === null ? null : $row['income'],
                'cost' => $row === null ? null : $row['cost'],
            ];
        }

        return $months;
    }

    /**
     * Lunes de las semanas del periodo (AAAA-MM-DD), sin huecos.
     *
     * @return list<string>
     */
    public static function weeks(ReportFilters $filters): array
    {
        $weeks = [];
        for ($monday = $filters->from->startOfWeek(CarbonImmutable::MONDAY); $monday->lessThanOrEqualTo($filters->to); $monday = $monday->addWeek()) {
            $weeks[] = $monday->toDateString();
        }

        return $weeks;
    }

    /**
     * Bolsas del proyecto (las del filtro de bolsa, si lo hay) con su consumo de toda la vida tal
     * como lo guarda HourBankLedger (la única fuente del consumo, el exceso y el saldo) y las horas
     * del periodo en el alcance. Dos consultas.
     *
     * @return list<BankRow>
     */
    public function banks(ReportScope $scope, Project $project): array
    {
        $banks = HourBank::query()
            ->where('project_id', $project->id)
            ->when($scope->filters->bankIds !== [], fn ($query) => $query->whereIn('id', $scope->filters->bankIds))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'status', 'start_date', 'end_date', 'total_minutes', 'consumed_minutes', 'overage_minutes', 'price_amount', 'hourly_rate']);

        if ($banks->isEmpty()) {
            return [];
        }

        $period = (clone $scope->entries())->toBase()
            ->whereIn('time_entries.hour_bank_id', $banks->modelKeys())
            ->selectRaw('time_entries.hour_bank_id as bank_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.hour_bank_id')
            ->pluck('minutes', 'bank_id');

        return array_values($banks->map(fn (HourBank $bank): array => [
            'id' => $bank->id,
            'name' => $bank->name,
            'status' => $bank->status->value,
            'start_date' => $bank->start_date->toDateString(),
            'end_date' => $bank->end_date?->toDateString(),
            'total_minutes' => $bank->total_minutes,
            'consumed_minutes' => $bank->consumed_minutes,
            'in_bank_minutes' => $bank->in_bank_minutes,
            'overage_minutes' => $bank->overage_minutes,
            'remaining_minutes' => $bank->remaining_minutes,
            'consumed_ratio' => $bank->total_minutes > 0 ? round($bank->consumed_minutes / $bank->total_minutes, 4) : 0.0,
            'period_minutes' => (int) ($period[$bank->id] ?? 0),
            'price_amount' => $bank->price_amount,
            'hourly_rate' => $bank->hourly_rate,
        ])->all());
    }

    /**
     * Cuántas entradas tiene el alcance. Una consulta.
     */
    public function entryCount(ReportScope $scope): int
    {
        return (clone $scope->entries())->toBase()->count();
    }

    /**
     * Las entradas del alcance en orden de fecha e id, por bloques (sin cargarlas todas): fecha,
     * persona, tarea y su tarea principal, franja (hora de Madrid, D-172), minutos, descripción,
     * facturable y estado. Como mucho $limit (null: todas).
     *
     * @return Generator<int, EntryRow>
     */
    public function entries(ReportScope $scope, ?int $limit = null): Generator
    {
        if ($limit !== null && $limit < 1) {
            return;
        }

        $written = 0;

        foreach (KeysetPages::byDateAndId(self::flat($scope), $limit === null ? self::CHUNK : min(self::CHUNK, $limit)) as $row) {
            yield [
                'id' => (int) $row->id,
                'date' => substr((string) $row->date, 0, 10),
                'user_id' => (int) $row->user_id,
                'person' => (string) $row->person_name,
                'task' => (string) $row->task_title,
                'parent' => $row->parent_title === null ? null : (string) $row->parent_title,
                'start' => self::localTime($row->started_at),
                'end' => self::localTime($row->ended_at),
                'minutes' => (int) $row->minutes,
                'description' => (string) $row->description,
                'billable' => (bool) $row->is_billable,
                'status' => (string) $row->status,
            ];

            // Sin pedir otro bloque si ya están todas las que caben.
            if ($limit !== null && ++$written >= $limit) {
                return;
            }
        }
    }

    /**
     * Texto del estado de una entrada («Aprobada»…).
     */
    public static function statusLabel(string $status): string
    {
        return TimeEntryStatus::tryFrom($status)?->label() ?? $status;
    }

    /**
     * Las columnas de cada entrada con los nombres de su persona, su tarea y la tarea principal por
     * LEFT JOIN (también los borrados), sin hidratar modelos.
     */
    private static function flat(ReportScope $scope): QueryBuilder
    {
        $query = clone $scope->entries();
        Dimension::ensureJoin($query, 'users');
        Dimension::ensureJoin($query, 'tasks');

        return $query->toBase()
            ->leftJoin('tasks as report_parent_tasks', 'report_parent_tasks.id', '=', 'report_tasks.parent_task_id')
            ->select(['time_entries.id', 'time_entries.date', 'time_entries.user_id', 'time_entries.minutes', 'time_entries.started_at',
                'time_entries.ended_at', 'time_entries.description', 'time_entries.is_billable', 'time_entries.status',
                'report_users.name as person_name', 'report_tasks.title as task_title', 'report_parent_tasks.title as parent_title']);
    }

    /**
     * Hora local (HH:MM en Madrid) de un instante guardado en UTC, o null.
     */
    private static function localTime(mixed $instant): ?string
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $instant, 'UTC')->setTimezone(LocalTime::timezone())->format('H:i');
    }
}
