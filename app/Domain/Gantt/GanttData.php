<?php

namespace App\Domain\Gantt;

use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Datos del Gantt (SPEC §6.1, D-060) de uno o varios proyectos, con pocas consultas y sin N+1:
 * - tareas (raíz y subtareas, completadas incluidas) con su estado, responsable, estimación
 *   efectiva, horas imputadas y si quien mira puede editarlas (TaskPolicy::update, GanttAccess),
 * - dependencias fin-inicio entre tareas que no están en la papelera (D-056),
 * - rango inicial de fechas: de la primera a la última fecha (tareas, proyecto y hoy) con margen.
 * Las fechas de tarea van como "Y-m-d", sin conversión de zona. Nunca datos económicos.
 *
 * Contrato: resources/js/components/gantt/types.ts (GanttTask, GanttRange).
 */
final class GanttData
{
    /** Días de margen antes de la primera fecha y después de la última. */
    public const int MARGIN_BEFORE = 7;

    public const int MARGIN_AFTER = 14;

    /** Sin ninguna fecha: de una semana antes de hoy a un mes después. */
    public const int EMPTY_AFTER = 30;

    /** @var list<array{id: int, name: string, color: string, category: string}>|null */
    private ?array $statuses = null;

    private const array COLUMNS = [
        'id', 'project_id', 'parent_task_id', 'title', 'status_id', 'assignee_user_id', 'start_date',
        'due_date', 'estimated_minutes', 'is_milestone', 'completed_at', 'position',
    ];

    /**
     * Estados de tarea en su orden (leyenda «por estado» y estado de cada tarea). Una consulta por
     * petición aunque se pidan las tareas y los estados.
     *
     * @return list<array{id: int, name: string, color: string, category: string}>
     */
    public function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $statuses = [];
        foreach (TaskStatus::query()->ordered()->get(['id', 'name', 'color', 'category']) as $status) {
            $statuses[] = [
                'id' => $status->id,
                'name' => $status->name,
                'color' => $status->color,
                'category' => $status->category->value,
            ];
        }

        return $this->statuses = $statuses;
    }

    /**
     * Tareas de los proyectos, en el orden de la lista (posición e id); las subtareas, después
     * de su tarea en el cliente (parent_task_id).
     *
     * - estimated_minutes: la efectiva (SPEC §6): con subtareas estimadas, su suma.
     * - logged_minutes: horas de la tarea y, en las tareas con subtareas, también las de estas;
     *   null sin $withLogged (un colaborador externo no ve las horas de todos, D-134).
     *
     * @param  list<int>  $projectIds
     * @param  array<int, bool>  $editable  id del proyecto → puede editar sus tareas
     * @return list<array<string, mixed>>
     */
    public function tasks(array $projectIds, array $editable, bool $withLogged = true): array
    {
        if ($projectIds === []) {
            return [];
        }

        $statuses = [];
        foreach ($this->statuses() as $status) {
            $statuses[$status['id']] = $status;
        }

        /** @var Collection<int, Task> $tasks */
        $tasks = Task::query()
            ->select(self::COLUMNS)
            ->whereIn('project_id', $projectIds)
            ->with('assignee:id,name,avatar_path')
            ->withSum('timeEntries as logged_sum', 'minutes')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        /** @var array<int, list<Task>> $children */
        $children = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null) {
                $children[$task->parent_task_id][] = $task;
            }
        }

        /** @var array<int, string|null> $avatars */
        $avatars = [];
        $rows = [];

        foreach ($tasks as $task) {
            $subtasks = $children[$task->id] ?? [];
            $estimated = $task->estimated_minutes;
            $logged = self::minutes($task);

            if ($subtasks !== []) {
                $subEstimates = array_values(array_filter(array_map(fn (Task $sub): ?int => $sub->estimated_minutes, $subtasks), fn (?int $minutes): bool => $minutes !== null));
                $estimated = $subEstimates === [] ? $estimated : array_sum($subEstimates);
                $logged += array_sum(array_map(fn (Task $sub): int => self::minutes($sub), $subtasks));
            }

            $status = $statuses[$task->status_id] ?? null;
            $assignee = $task->assignee;

            if ($assignee !== null && ! array_key_exists($assignee->id, $avatars)) {
                $avatars[$assignee->id] = $assignee->avatar_url;
            }

            $rows[] = [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'parent_task_id' => $task->parent_task_id,
                'title' => $task->title,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'is_milestone' => $task->is_milestone,
                'is_completed' => $task->completed_at !== null,
                'status' => $status,
                'assignee' => $assignee === null ? null : [
                    'id' => $assignee->id,
                    'name' => $assignee->name,
                    'avatar' => $avatars[$assignee->id],
                ],
                'estimated_minutes' => $task->is_milestone ? null : $estimated,
                'logged_minutes' => $withLogged ? $logged : null,
                'subtasks_count' => count($subtasks),
                'can' => ['update' => $editable[$task->project_id] ?? false],
            ];
        }

        return $rows;
    }

    /**
     * Dependencias fin-inicio de los proyectos cuyas dos tareas siguen vivas (D-056: una tarea en
     * la papelera no cuenta). Contrato: TaskDependencyItem (resources/js/types/schedule.ts).
     *
     * @param  list<int>  $projectIds
     * @return list<array{id: int, predecessor_task_id: int, successor_task_id: int, type: string}>
     */
    public function dependencies(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = TaskDependency::query()
            ->join('tasks as gantt_pred', 'gantt_pred.id', '=', 'task_dependencies.predecessor_task_id')
            ->join('tasks as gantt_succ', 'gantt_succ.id', '=', 'task_dependencies.successor_task_id')
            ->whereIn('gantt_pred.project_id', $projectIds)
            ->whereNull('gantt_pred.deleted_at')
            ->whereNull('gantt_succ.deleted_at')
            ->orderBy('task_dependencies.id')
            ->get([
                'task_dependencies.id',
                'task_dependencies.predecessor_task_id',
                'task_dependencies.successor_task_id',
                'task_dependencies.type',
            ]);

        $dependencies = [];
        foreach ($rows as $dependency) {
            $dependencies[] = [
                'id' => $dependency->id,
                'predecessor_task_id' => $dependency->predecessor_task_id,
                'successor_task_id' => $dependency->successor_task_id,
                'type' => $dependency->type,
            ];
        }

        return $dependencies;
    }

    /**
     * Rango inicial (Y-m-d, ambos incluidos): de la primera a la última fecha de las tareas, de los
     * proyectos y de hoy, con MARGIN_BEFORE y MARGIN_AFTER días de margen.
     *
     * @param  list<array<string, mixed>>  $tasks  filas de tasks()
     * @param  list<CarbonImmutable|null>  $projectDates  inicios y entregas de los proyectos
     * @return array{start: string, end: string}
     */
    public function range(array $tasks, array $projectDates, CarbonImmutable $today): array
    {
        $dates = [$today->toDateString()];

        foreach ($tasks as $task) {
            foreach (['start_date', 'due_date'] as $field) {
                if (is_string($task[$field] ?? null)) {
                    $dates[] = $task[$field];
                }
            }
        }

        foreach ($projectDates as $date) {
            if ($date !== null) {
                $dates[] = $date->toDateString();
            }
        }

        if (count($dates) === 1) {
            return [
                'start' => $today->subDays(self::MARGIN_BEFORE)->toDateString(),
                'end' => $today->addDays(self::EMPTY_AFTER)->toDateString(),
            ];
        }

        sort($dates);

        return [
            'start' => CarbonImmutable::parse($dates[0])->subDays(self::MARGIN_BEFORE)->toDateString(),
            'end' => CarbonImmutable::parse($dates[count($dates) - 1])->addDays(self::MARGIN_AFTER)->toDateString(),
        ];
    }

    private static function minutes(Task $task): int
    {
        $sum = $task->getAttribute('logged_sum');

        return is_numeric($sum) ? (int) $sum : 0;
    }
}
