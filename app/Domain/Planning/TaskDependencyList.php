<?php

namespace App\Domain\Planning;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * Sección «Dependencias» del panel de la tarea (D-056, D-062): de qué tareas depende
 * (predecesoras) y a cuáles bloquea (sucesoras), con la marca de conflicto de D-057. Una sola
 * consulta; las tareas en la papelera no cuentan. Contrato: resources/js/types/planning.ts
 * (TaskPanelDependencies).
 */
final class TaskDependencyList
{
    private const array COLUMNS = [
        'tasks.id', 'tasks.title', 'tasks.parent_task_id', 'tasks.status_id', 'tasks.start_date',
        'tasks.due_date', 'tasks.is_milestone', 'tasks.completed_at',
    ];

    /**
     * @return array{predecessors: list<array<string, mixed>>, successors: list<array<string, mixed>>}
     */
    public function for(Task $task): array
    {
        $linked = Task::query()
            ->join('task_dependencies as dep', fn (JoinClause $join) => $join
                ->on('dep.predecessor_task_id', '=', 'tasks.id')
                ->orOn('dep.successor_task_id', '=', 'tasks.id'))
            ->where(fn ($link) => $link->where('dep.successor_task_id', $task->id)->orWhere('dep.predecessor_task_id', $task->id))
            ->where('tasks.id', '!=', $task->id)
            ->where('tasks.project_id', $task->project_id)
            ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tasks.due_date')
            ->orderBy('tasks.id')
            ->get([...self::COLUMNS, 'dep.id as dependency_id', 'dep.predecessor_task_id as dependency_predecessor_id']);

        $predecessors = [];
        $successors = [];

        foreach ($linked as $other) {
            $isPredecessor = (int) $other->getAttribute('dependency_predecessor_id') === $other->id;

            if ($isPredecessor) {
                $predecessors[] = $this->item($other, self::conflict($other, $task));
            } else {
                $successors[] = $this->item($other, self::conflict($task, $other));
            }
        }

        return ['predecessors' => $predecessors, 'successors' => $successors];
    }

    /**
     * ¿Está la sucesora en conflicto con su predecesora? (D-057): empieza (o, sin inicio, vence) el
     * mismo día o antes de que acabe la predecesora. Sin fechas no hay conflicto.
     */
    public static function conflict(Task $predecessor, Task $successor): bool
    {
        $end = $predecessor->due_date;
        $begin = $successor->start_date ?? $successor->due_date;

        return $end !== null && $begin !== null && self::day($begin) <= self::day($end);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Task $task, bool $conflict): array
    {
        return [
            'dependency_id' => (int) $task->getAttribute('dependency_id'),
            'conflict' => $conflict,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'parent_task_id' => $task->parent_task_id,
                'status_id' => $task->status_id,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'is_milestone' => $task->is_milestone,
                'is_completed' => $task->isCompleted(),
            ],
        ];
    }

    private static function day(CarbonImmutable $date): string
    {
        return $date->toDateString();
    }
}
