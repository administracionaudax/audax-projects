<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Fila de la lista y tarjeta del kanban (contrato: resources/js/types/tasks.ts, TaskListItem).
 * Amplía TaskResource con las subtareas (un nivel) y la estimación efectiva: la suma de las
 * subtareas si alguna tiene estimación (SPEC §6). Cargar antes `assignee`, `subtasks.assignee` y
 * withSum('timeEntries', 'minutes') en las tareas y en las subtareas (sin N+1).
 *
 * @mixin Task
 */
class TaskListItemResource extends TaskResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;
        $fromSubtasks = ! $task->isSubtask()
            && $task->relationLoaded('subtasks')
            && $task->subtasks->whereNotNull('estimated_minutes')->isNotEmpty();

        return [
            ...parent::toArray($request),
            'subtasks' => $this->when(! $task->isSubtask(), fn () => self::collection($task->relationLoaded('subtasks') ? $task->subtasks : [])),
            'estimate_from_subtasks' => $fromSubtasks,
            'effective_estimated_minutes' => $fromSubtasks
                ? (int) $task->subtasks->sum('estimated_minutes')
                : $task->estimated_minutes,
        ];
    }
}
