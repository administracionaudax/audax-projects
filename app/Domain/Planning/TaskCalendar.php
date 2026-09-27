<?php

namespace App\Domain\Planning;

use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\UserSummaryResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Datos de la vista Calendario de la pestaña Tareas (D-061). Contrato: resources/js/types/planning.ts
 * (TaskCalendarData).
 * - Tareas del periodo: las que vencen en él y, si tienen inicio, las que lo cruzan (franja del
 *   inicio a la entrega). Tareas y subtareas por igual, cada una con sus fechas.
 * - Sin fecha: las que no tienen entrega (las más recientes, como mucho UNDATED_LIMIT, con el total).
 * - Mismos filtros que la lista y el kanban (responsable, mías, bolsa, tipo, prioridad, estado y
 *   completadas), aplicados a cada tarea.
 * - Sin datos económicos y con pocas consultas: tareas, sin fecha, responsables y tareas padre
 *   (estas dos, juntas para las dos listas).
 */
final class TaskCalendar
{
    public const int UNDATED_LIMIT = 100;

    public const array COLUMNS = [
        'id', 'project_id', 'parent_task_id', 'title', 'status_id', 'priority', 'assignee_user_id',
        'start_date', 'due_date', 'is_milestone', 'completed_at', 'position',
    ];

    /**
     * @param  array{assignee: int|'none'|null, bank: int|null, type: int|null, priority: string|null, status: int|null, mine: bool, completed: bool, group: string}  $filters
     * @return array{mode: string, period: string, from: string, to: string, today: string, tasks: list<array<string, mixed>>, undated: list<array<string, mixed>>, undated_total: int}
     */
    public function build(Project $project, User $viewer, array $filters, CalendarPeriod $period): array
    {
        $from = $period->fromString();
        $to = $period->toString();

        /** @var Collection<int, Task> $dated */
        $dated = $this->query($project, $viewer, $filters)
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $from)
            // Vence dentro del periodo o empieza antes de que acabe (su franja lo cruza).
            ->where(fn (Builder $inside) => $inside->where('due_date', '<=', $to)
                ->orWhere(fn (Builder $span) => $span->whereNotNull('start_date')->where('start_date', '<=', $to)))
            ->orderBy('due_date')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Task> $undated */
        $undated = $this->query($project, $viewer, $filters)
            ->whereNull('due_date')
            ->orderByDesc('id')
            ->limit(self::UNDATED_LIMIT)
            ->get();

        $undatedTotal = $undated->count() < self::UNDATED_LIMIT
            ? $undated->count()
            : $this->query($project, $viewer, $filters)->whereNull('due_date')->count();

        // Responsables y tareas padre de las dos listas, con una consulta cada uno.
        (new Collection([...$dated->all(), ...$undated->all()]))->load([
            'assignee:id,name,avatar_path,department_id,is_active',
            'parent:id,title',
        ]);

        return [
            'mode' => $period->mode,
            'period' => $period->key,
            'from' => $from,
            'to' => $to,
            'today' => LocalTime::todayString(),
            'tasks' => array_values($dated->map(fn (Task $task): array => $this->item($task))->all()),
            'undated' => array_values($undated->map(fn (Task $task): array => $this->item($task))->all()),
            'undated_total' => $undatedTotal,
        ];
    }

    /**
     * @param  array{assignee: int|'none'|null, bank: int|null, type: int|null, priority: string|null, status: int|null, mine: bool, completed: bool, group: string}  $filters
     * @return Builder<Task>
     */
    private function query(Project $project, User $viewer, array $filters): Builder
    {
        return Task::query()
            ->select(self::COLUMNS)
            ->where('project_id', $project->id)
            ->when(! $filters['completed'], fn (Builder $query) => $query->open())
            ->when($filters['assignee'] === 'none', fn (Builder $query) => $query->whereNull('assignee_user_id'))
            ->when(is_int($filters['assignee']), fn (Builder $query) => $query->where('assignee_user_id', $filters['assignee']))
            ->when($filters['mine'], fn (Builder $query) => $query->where('assignee_user_id', $viewer->id))
            ->when($filters['bank'] !== null, fn (Builder $query) => $query->where('hour_bank_id', $filters['bank']))
            ->when($filters['type'] !== null, fn (Builder $query) => $query->where('task_type_id', $filters['type']))
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('priority', $filters['priority']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status_id', $filters['status']));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'parent_task_id' => $task->parent_task_id,
            'parent_title' => $task->parent?->title,
            'status_id' => $task->status_id,
            'priority' => $task->priority->value,
            'assignee' => $task->assignee === null ? null : Plain::of(UserSummaryResource::make($task->assignee)),
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'is_milestone' => $task->is_milestone,
            'is_completed' => $task->isCompleted(),
        ];
    }
}
