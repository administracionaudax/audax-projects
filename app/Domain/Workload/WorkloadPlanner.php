<?php

namespace App\Domain\Workload;

use App\Domain\Time\Capacity;
use App\Enums\ProjectStatus;
use App\Models\Task;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Carga planificada (SPEC §9). Para cada tarea ABIERTA con responsable, estimación y fechas:
 * - restante = max(estimación − minutos ya imputados, 0),
 * - se reparte uniformemente entre los días con capacidad > 0 del responsable desde
 *   max(hoy, start_date) hasta due_date (sin start_date: desde hoy); los minutos que no dividen
 *   exacto van a los primeros días,
 * - vencida (due_date < hoy): todo el restante va a hoy y la tarea se marca como vencida,
 * - si no hay ningún día con capacidad en el rango, todo va al primer día del rango,
 * - con subtareas, cuenta la carga de las subtareas y no la del padre,
 * - sin estimación o sin fecha de entrega: no suma carga; va a «Sin planificar»,
 * - sin responsable: va a «Sin asignar», por departamento (el de la bolsa o, si no, el del tipo).
 * Los hitos y los proyectos archivados no cuentan. La capacidad sale de Capacity (festivos y
 * ausencias incluidos).
 */
final class WorkloadPlanner
{
    /** Máximo de días hacia delante que se calculan (tareas con entrega muy lejana). */
    public const int MAX_DAYS_AHEAD = 366;

    public function __construct(private readonly Capacity $capacity) {}

    /**
     * @param  list<int>  $userIds  Personas de la vista.
     * @param  array{project_ids?: list<int>, client_ids?: list<int>, department_ids?: list<int>}  $filters
     */
    public function plan(array $userIds, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $today = null, array $filters = []): WorkloadPlan
    {
        $today = ($today ?? LocalTime::today())->startOfDay();
        $plan = new WorkloadPlan;
        // Las fechas de la vista, una vez (se comparan por cada día de cada tarea: rendimiento).
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $tasks = $this->openTasks($filters)
            ->where(fn (Builder $q) => $q->whereIn('assignee_user_id', $userIds)->orWhereNull('assignee_user_id'))
            ->get();

        $assigned = $tasks->whereNotNull('assignee_user_id');
        $limit = $today->addDays(self::MAX_DAYS_AHEAD);

        // Capacidad de cada persona desde hoy (o desde el inicio de la vista) hasta la entrega más lejana.
        // La entrega más lejana de cada persona, en una sola pasada por las tareas (rendimiento).
        $latestDue = [];
        foreach ($assigned as $task) {
            $due = $task->due_date?->toDateString();
            if ($due !== null && $due > ($latestDue[$task->assignee_user_id] ?? '')) {
                $latestDue[$task->assignee_user_id] = $due;
            }
        }
        $horizonStart = $from < $today ? $from : $today;
        $ranges = [];
        foreach ($userIds as $userId) {
            $latest = $latestDue[$userId] ?? null;
            $end = max($to->toDateString(), is_string($latest) ? $latest : $to->toDateString());
            $end = min($end, $limit->toDateString());
            $ranges[] = ['user_id' => $userId, 'from' => $horizonStart, 'to' => CarbonImmutable::parse($end)];
        }
        $capacity = [];
        foreach ($this->capacity->forRanges($ranges) as $index => $days) {
            $capacity[$ranges[$index]['user_id']] = $days;
        }

        foreach ($userIds as $userId) {
            $plan->capacity[$userId] = array_filter(
                $capacity[$userId] ?? [],
                fn (string $date): bool => $date >= $fromDate && $date <= $toDate,
                ARRAY_FILTER_USE_KEY,
            );
        }

        foreach ($tasks as $task) {
            if ($task->assignee_user_id === null) {
                $department = $task->hourBank->department_id ?? $task->type->department_id ?? null;
                $plan->unassigned[(string) ($department ?? '')][] = [
                    'task_id' => $task->id,
                    'remaining_minutes' => $this->remaining($task),
                ];

                continue;
            }

            if (! $task->estimated_minutes) {
                $plan->unplanned[] = ['task_id' => $task->id, 'user_id' => $task->assignee_user_id, 'reason' => 'no_estimate'];

                continue;
            }

            if ($task->due_date === null) {
                $plan->unplanned[] = ['task_id' => $task->id, 'user_id' => $task->assignee_user_id, 'reason' => 'no_dates'];

                continue;
            }

            $remaining = $this->remaining($task);
            if ($remaining <= 0) {
                continue;
            }

            $days = $this->distribute($task, $remaining, $capacity[$task->assignee_user_id] ?? [], $today, $limit, $plan);

            foreach ($days as $date => $minutes) {
                if ($date < $fromDate || $date > $toDate) {
                    continue;
                }
                $plan->load[$task->assignee_user_id][$date] = ($plan->load[$task->assignee_user_id][$date] ?? 0) + $minutes;
                $plan->contributions[$task->assignee_user_id][$date][$task->id] = $minutes;
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, int>  $capacity
     * @return array<string, int> fecha → minutos
     */
    private function distribute(Task $task, int $remaining, array $capacity, CarbonImmutable $today, CarbonImmutable $limit, WorkloadPlan $plan): array
    {
        $due = CarbonImmutable::parse((string) $task->due_date?->toDateString());

        if ($due < $today) {
            $plan->overdue[] = $task->id;

            return [$today->toDateString() => $remaining];
        }

        $start = $task->start_date !== null && $task->start_date->toDateString() > $today->toDateString()
            ? CarbonImmutable::parse($task->start_date->toDateString())
            : $today;
        if ($start > $due) {
            $start = $due;
        }
        $end = $due > $limit ? $limit : $due;

        // Los días de $start a $end (ambos incluidos, como CarbonPeriod) como fechas UTC: sin crear
        // un Carbon por día (rendimiento). El último es la fecha de $end en la zona de $start.
        $working = [];
        $last = (int) strtotime($end->setTimezone($start->getTimezone())->toDateString().' 00:00:00 UTC');
        for ($time = (int) strtotime($start->toDateString().' 00:00:00 UTC'); $time <= $last; $time += 86400) {
            $date = gmdate('Y-m-d', $time);
            if (($capacity[$date] ?? 0) > 0) {
                $working[] = $date;
            }
        }

        if ($working === []) {
            return [$start->toDateString() => $remaining];
        }

        $share = intdiv($remaining, count($working));
        $extra = $remaining % count($working);
        $result = [];
        foreach ($working as $index => $date) {
            $minutes = $share + ($index < $extra ? 1 : 0);
            if ($minutes > 0) {
                $result[$date] = $minutes;
            }
        }

        return $result;
    }

    private function remaining(Task $task): int
    {
        return max((int) $task->estimated_minutes - (int) ($task->time_entries_sum_minutes ?? 0), 0);
    }

    /**
     * Tareas abiertas que cuentan para la carga: sin hitos, de proyectos no archivados y sin las
     * que tienen subtareas (cuentan sus subtareas).
     *
     * @param  array{project_ids?: list<int>, client_ids?: list<int>, department_ids?: list<int>}  $filters
     * @return Builder<Task>
     */
    public function openTasks(array $filters = []): Builder
    {
        return Task::query()
            ->open()
            ->where('is_milestone', false)
            ->whereHas('project', fn (Builder $q) => $q->where('status', '!=', ProjectStatus::Archived->value))
            ->whereDoesntHave('subtasks')
            ->when(($filters['project_ids'] ?? []) !== [], fn (Builder $q) => $q->whereIn('project_id', $filters['project_ids'] ?? []))
            ->when(($filters['client_ids'] ?? []) !== [], fn (Builder $q) => $q->whereHas('project', fn (Builder $p) => $p->whereIn('client_id', $filters['client_ids'] ?? [])))
            ->select(['id', 'project_id', 'hour_bank_id', 'parent_task_id', 'task_type_id', 'assignee_user_id', 'title', 'start_date', 'due_date', 'estimated_minutes', 'completed_at', 'is_milestone'])
            // Después de select(): withSum añade su subconsulta a las columnas.
            ->withSum('timeEntries', 'minutes')
            ->with(['hourBank:id,department_id', 'type:id,department_id']);
    }
}
