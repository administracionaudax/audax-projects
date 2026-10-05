<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Fila de la lista y tarjeta del kanban (contrato: resources/js/types/tasks.ts, TaskListItem).
 * Amplía TaskResource con las subtareas (un nivel) y la estimación efectiva: la suma de las
 * subtareas si alguna tiene estimación (SPEC §6). Cargar antes `assignee`, `subtasks.assignee` y
 * withSum('timeEntries', 'minutes') en las tareas y en las subtareas (sin N+1). En las raíz añade
 * subtasks_logged_minutes, la suma de lo imputado en sus subtareas (D-170).
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
        self::withSubtasksLogged($task);
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

    /**
     * Lo imputado en las subtareas (D-170) a partir de las subtareas ya cargadas con su
     * withSum('timeEntries', 'minutes'): sin consultas. Solo en las tareas raíz.
     */
    public static function withSubtasksLogged(Task $task): void
    {
        if ($task->isSubtask() || ! $task->relationLoaded('subtasks') || array_key_exists('subtasks_logged_minutes', $task->getAttributes())) {
            return;
        }

        // Sin el withSum en las subtareas no se sabe: mejor no enviar nada que enviar un 0.
        if ($task->subtasks->contains(fn (Task $subtask): bool => ! array_key_exists('time_entries_sum_minutes', $subtask->getAttributes()))) {
            return;
        }

        $task->setAttribute('subtasks_logged_minutes', (int) $task->subtasks->sum(fn (Task $subtask): int => (int) ($subtask->getAttribute('time_entries_sum_minutes') ?? 0)));
    }
}
