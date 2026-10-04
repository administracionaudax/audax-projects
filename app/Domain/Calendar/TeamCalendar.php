<?php

namespace App\Domain\Calendar;

use App\Domain\Gantt\GanttAccess;
use App\Enums\ProjectStatus;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\UserSummaryResource;
use App\Models\Task;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tareas del calendario del equipo (D-144). Contrato: resources/js/types/calendar.ts
 * (TeamCalendarData).
 * - Qué entra: las tareas (y subtareas) con inicio, entrega o las dos que tocan el rango visible:
 *   vencen en él, empiezan en él o lo cruzan (empiezan antes y vencen después). De proyectos sin
 *   archivar que quien mira puede ver (D-021; un colaborador, solo los suyos, D-134). Abiertas
 *   salvo «incluir hechas».
 * - Una consulta acotada por el rango (índices tasks(due_date, start_date) y tasks(start_date)),
 *   con el código, el nombre y el color del proyecto en la misma fila; después, las tareas padre y
 *   los responsables (una consulta cada uno) y quién puede editar (GanttAccess, la regla de
 *   TaskPolicy::update para muchos proyectos a la vez). Como mucho MAX_TASKS: si hay más, se
 *   avisa y se pide filtrar.
 * - Sin datos económicos ni horas.
 */
final class TeamCalendar
{
    use MatchesText;

    public const int MAX_TASKS = 1500;

    private const array COLUMNS = [
        'id', 'project_id', 'parent_task_id', 'title', 'status_id', 'priority', 'assignee_user_id',
        'start_date', 'due_date', 'is_milestone', 'completed_at', 'task_type_id', 'estimated_minutes',
    ];

    public function __construct(private readonly GanttAccess $access) {}

    /**
     * @return array{
     *     view: string, date: string, from: string, to: string, today: string, people_view: bool,
     *     tasks: list<array<string, mixed>>, projects: list<array<string, mixed>>,
     *     assignees: list<array<string, mixed>>, truncated: bool, total: int, limit: int
     * }
     */
    public function build(User $viewer, CalendarFilters $filters, CalendarRange $range): array
    {
        // Filas planas (sin modelos ni fechas Carbon): con cientos de tareas por mes, hidratar
        // modelos es lo que más cuesta. Las fechas llegan como «AAAA-MM-DD» (PostgreSQL) o
        // «AAAA-MM-DD 00:00:00» (SQLite).
        $rows = $this->query($viewer, $filters, $range)
            ->orderByRaw('COALESCE(tasks.due_date, tasks.start_date)')
            ->orderBy('tasks.id')
            ->limit(self::MAX_TASKS + 1)
            ->toBase()
            ->get();

        $truncated = $rows->count() > self::MAX_TASKS;
        $rows = $rows->take(self::MAX_TASKS);
        $total = $truncated ? $this->query($viewer, $filters, $range)->count() : $rows->count();

        $parentIds = $rows->pluck('parent_task_id')->filter()->unique()->values()->all();
        $parents = $parentIds === [] ? [] : Task::query()->whereIn('id', $parentIds)->pluck('title', 'id')->all();

        $assigneeIds = $rows->pluck('assignee_user_id')->filter()->unique()->values()->all();
        $assignees = $assigneeIds === [] ? [] : array_values(User::query()
            ->whereIn('id', $assigneeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path', 'department_id', 'is_active'])
            ->map(fn (User $user): array => Plain::of(UserSummaryResource::make($user)))
            ->all());

        $projects = [];
        foreach ($rows as $row) {
            $projects[(int) $row->project_id] ??= [
                'id' => (int) $row->project_id,
                'code' => (string) $row->project_code,
                'name' => (string) $row->project_name,
                'color' => (string) $row->project_color,
            ];
        }

        $editable = $this->access->editable($viewer, array_keys($projects));
        foreach ($projects as $id => $project) {
            $projects[$id]['can_update'] = $editable[$id] ?? false;
        }

        $tasks = [];
        foreach ($rows as $row) {
            $parentId = $row->parent_task_id === null ? null : (int) $row->parent_task_id;

            $tasks[] = [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'project_id' => (int) $row->project_id,
                'parent_task_id' => $parentId,
                'parent_title' => $parentId === null ? null : ($parents[$parentId] ?? null),
                'status_id' => (int) $row->status_id,
                'priority' => (string) $row->priority,
                'task_type_id' => $row->task_type_id === null ? null : (int) $row->task_type_id,
                'assignee_id' => $row->assignee_user_id === null ? null : (int) $row->assignee_user_id,
                'start_date' => self::date($row->start_date),
                'due_date' => self::date($row->due_date),
                'is_milestone' => (bool) $row->is_milestone,
                'is_completed' => $row->completed_at !== null,
                'estimated_minutes' => $row->estimated_minutes === null ? null : (int) $row->estimated_minutes,
            ];
        }

        return [
            'view' => $filters->view,
            'date' => $filters->date,
            'from' => $range->fromString(),
            'to' => $range->toString(),
            'today' => LocalTime::todayString(),
            'people_view' => $filters->people,
            'tasks' => $tasks,
            'projects' => array_values($projects),
            'assignees' => $assignees,
            'truncated' => $truncated,
            'total' => $total,
            'limit' => self::MAX_TASKS,
        ];
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * @return Builder<Task>
     */
    private function query(User $viewer, CalendarFilters $filters, CalendarRange $range): Builder
    {
        $from = $range->fromString();
        $end = $range->endExclusive();

        $query = Task::query()
            ->select(array_map(fn (string $column): string => "tasks.{$column}", self::COLUMNS))
            ->addSelect(['projects.code as project_code', 'projects.name as project_name', 'projects.color as project_color'])
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->visibleTo($viewer)
            // Vence en el rango, empieza en él o lo cruza.
            ->where(fn (Builder $inside) => $inside
                ->where(fn (Builder $due) => $due->where('tasks.due_date', '>=', $from)->where('tasks.due_date', '<', $end))
                ->orWhere(fn (Builder $start) => $start->where('tasks.start_date', '>=', $from)->where('tasks.start_date', '<', $end))
                ->orWhere(fn (Builder $across) => $across->where('tasks.due_date', '>=', $end)->where('tasks.start_date', '<', $from)))
            ->when(! $filters->done, fn (Builder $open) => $open->whereNull('tasks.completed_at'))
            ->when($filters->projects !== [], fn (Builder $q) => $q->whereIn('tasks.project_id', $filters->projects))
            ->when($filters->clients !== [], fn (Builder $q) => $q->whereIn('projects.client_id', $filters->clients))
            ->when($filters->priority !== null, fn (Builder $q) => $q->where('tasks.priority', $filters->priority))
            ->when($filters->types !== [], fn (Builder $q) => $q->whereIn('tasks.task_type_id', $filters->types))
            ->when($filters->milestones, fn (Builder $q) => $q->where('tasks.is_milestone', true))
            ->when($filters->department !== null, fn (Builder $q) => $q->whereIn(
                'tasks.assignee_user_id',
                User::query()->select('id')->where('department_id', $filters->department),
            ));

        if ($filters->mine) {
            $query->where('tasks.assignee_user_id', $viewer->id);
        } elseif ($filters->persons !== [] || $filters->unassigned) {
            $query->where(fn (Builder $who) => $who
                ->when($filters->persons !== [], fn (Builder $q) => $q->whereIn('tasks.assignee_user_id', $filters->persons))
                ->when($filters->unassigned, fn (Builder $q) => $q->orWhereNull('tasks.assignee_user_id')));
        }

        if ($filters->q !== null) {
            $query->leftJoin('clients', 'clients.id', '=', 'projects.client_id');
            $this->whereMatches($query, ['tasks.title', 'projects.code', 'projects.name', 'clients.name'], $filters->q);
        }

        return $query;
    }
}
