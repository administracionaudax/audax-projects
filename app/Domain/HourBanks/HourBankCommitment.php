<?php

namespace App\Domain\HourBanks;

use App\Models\HourBank;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Horas comprometidas de las bolsas (SPEC §8, UI): lo que falta por hacer de sus tareas abiertas.
 *
 * comprometidas = Σ max(estimación efectiva − minutos imputados a esa tarea EN ESA BOLSA, 0)
 * sobre las tareas abiertas de la bolsa, contando las subtareas y no el padre cuya estimación
 * deriva de ellas (D-037: la del padre es la suma de sus subtareas estimadas). Un padre sin
 * subtareas estimadas cuenta con su propia estimación. Los hitos no llevan horas.
 *
 * Aviso: si consumido + comprometido supera el total, las tareas planificadas superan el saldo.
 * open_tasks_count: todas las tareas abiertas de la bolsa (de cualquier nivel, también los hitos),
 * que son las que mueve HourBankRenewal al renovar.
 * Se calcula en tres consultas para cualquier número de bolsas (sin N+1).
 */
final class HourBankCommitment
{
    /**
     * @param  iterable<int>  $bankIds
     * @return array<int, array{committed_minutes: int, open_tasks_count: int}>
     */
    public function forBanks(iterable $bankIds): array
    {
        $ids = [];
        foreach ($bankIds as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));

        $committed = array_fill_keys($ids, 0);
        $openTasksCount = array_fill_keys($ids, 0);

        if ($ids !== []) {
            $openTasks = Task::query()
                ->whereIn('hour_bank_id', $ids)
                ->whereNull('completed_at')
                ->get(['id', 'hour_bank_id', 'parent_task_id', 'estimated_minutes', 'is_milestone']);

            $derivedParents = $this->derivedParents($ids);
            $logged = $this->loggedByTask($ids, openOnly: true);

            foreach ($openTasks as $task) {
                $bankId = (int) $task->hour_bank_id;
                $openTasksCount[$bankId] = ($openTasksCount[$bankId] ?? 0) + 1;

                // Los hitos no llevan horas; un padre con subtareas estimadas cuenta en ellas.
                if ($task->is_milestone || ($task->parent_task_id === null && isset($derivedParents[$task->id]))) {
                    continue;
                }

                $committed[$bankId] = ($committed[$bankId] ?? 0) + max(
                    ($task->estimated_minutes ?? 0) - ($logged[$bankId][$task->id] ?? 0),
                    0,
                );
            }
        }

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'committed_minutes' => $committed[$id] ?? 0,
                'open_tasks_count' => $openTasksCount[$id] ?? 0,
            ];
        }

        return $result;
    }

    public function committedFor(HourBank $bank): int
    {
        return $this->forBanks([$bank->id])[$bank->id]['committed_minutes'];
    }

    /**
     * Tareas de la bolsa para su detalle: raíz y, debajo, sus subtareas; con la estimación
     * efectiva, lo imputado en esta bolsa y lo comprometido (null en un padre cuya estimación
     * sale de sus subtareas: ya cuentan ellas).
     *
     * @return list<array{task: Task, depth: int, estimated_minutes: int|null, logged_minutes: int, committed_minutes: int|null}>
     */
    public function tasksFor(HourBank $bank): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = Task::query()
            ->where('hour_bank_id', $bank->id)
            ->with(['status:id,name,color,category', 'assignee:id,name,avatar_path,department_id,is_active'])
            ->orderBy('position')
            ->orderBy('id')
            ->get([
                'id', 'project_id', 'hour_bank_id', 'parent_task_id', 'title', 'task_type_id', 'status_id',
                'priority', 'assignee_user_id', 'start_date', 'due_date', 'estimated_minutes', 'is_billable',
                'is_milestone', 'position', 'completed_at',
            ]);

        $logged = $this->loggedByTask([$bank->id], openOnly: false)[$bank->id] ?? [];
        $ids = $tasks->modelKeys();

        /** @var array<int, list<Task>> $children */
        $children = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null && in_array($task->parent_task_id, $ids, true)) {
                $children[$task->parent_task_id][] = $task;
            }
        }

        $rows = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null && isset($children[$task->parent_task_id])) {
                continue; // Se pinta debajo de su padre.
            }

            $subtasks = $children[$task->id] ?? [];
            $task->setRelation('subtasks', new Collection($subtasks));
            $derived = $task->parent_task_id === null
                && collect($subtasks)->contains(fn (Task $subtask): bool => $subtask->estimated_minutes !== null);

            $rows[] = $this->row($task, 0, $logged, $derived);

            foreach ($subtasks as $subtask) {
                $subtask->setRelation('subtasks', new Collection);
                $rows[] = $this->row($subtask, 1, $logged, false);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $logged
     * @return array{task: Task, depth: int, estimated_minutes: int|null, logged_minutes: int, committed_minutes: int|null}
     */
    private function row(Task $task, int $depth, array $logged, bool $derived): array
    {
        $minutes = $logged[$task->id] ?? 0;
        $estimated = $task->effectiveEstimatedMinutes();

        $committed = match (true) {
            $derived => null,
            $task->isCompleted() || $task->is_milestone => 0,
            default => max(($task->estimated_minutes ?? 0) - $minutes, 0),
        };

        return [
            'task' => $task,
            'depth' => $depth,
            'estimated_minutes' => $estimated,
            'logged_minutes' => $minutes,
            'committed_minutes' => $committed,
        ];
    }

    /**
     * Padres (de estas bolsas) con alguna subtarea estimada, abierta o no.
     *
     * @param  list<int>  $bankIds
     * @return array<int, true>
     */
    private function derivedParents(array $bankIds): array
    {
        $ids = Task::query()
            ->whereIn('hour_bank_id', $bankIds)
            ->whereNotNull('parent_task_id')
            ->whereNotNull('estimated_minutes')
            ->distinct()
            ->pluck('parent_task_id');

        $parents = [];
        foreach ($ids as $id) {
            $parents[(int) $id] = true;
        }

        return $parents;
    }

    /**
     * Minutos imputados por bolsa y tarea (solo las entradas de ESA bolsa).
     *
     * @param  list<int>  $bankIds
     * @return array<int, array<int, int>>
     */
    private function loggedByTask(array $bankIds, bool $openOnly): array
    {
        $rows = TimeEntry::query()
            ->whereIn('hour_bank_id', $bankIds)
            ->when($openOnly, fn ($query) => $query->whereIn(
                'task_id',
                Task::query()->select('id')->whereIn('hour_bank_id', $bankIds)->whereNull('completed_at'),
            ))
            ->groupBy('hour_bank_id', 'task_id')
            ->selectRaw('hour_bank_id, task_id, SUM(minutes) AS minutes')
            ->toBase()
            ->get();

        $logged = [];
        foreach ($rows as $row) {
            $logged[(int) $row->hour_bank_id][(int) $row->task_id] = (int) $row->minutes;
        }

        return $logged;
    }
}
