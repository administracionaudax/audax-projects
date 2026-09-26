<?php

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orden manual de las tareas (SPEC §4.3, `position`):
 * - las tareas raíz se ordenan dentro de su columna (proyecto + estado), como en el kanban,
 * - las subtareas, entre sus hermanas.
 * Las posiciones de una columna siempre quedan consecutivas (0, 1, 2…) al colocar una tarea.
 * El orden no se audita (Task::activityExcept), así que se renumera sin eventos de modelo.
 */
final class TaskPositions
{
    /**
     * Siguiente posición libre: al final de la columna (o de las subtareas del padre).
     */
    public function next(int $projectId, int $statusId, ?int $parentId = null): int
    {
        $max = $this->siblings($projectId, $statusId, $parentId)->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     * Coloca una tarea raíz en la columna del estado $statusId, justo antes de $beforeId o después
     * de $afterId (o al final si no se indica ninguna), y renumera la columna. Si cambia de estado,
     * se guarda con el modelo (completed_at y auditoría).
     *
     * @throws ValidationException si la tarea de referencia no está en esa columna
     */
    public function place(Task $task, int $statusId, ?int $beforeId = null, ?int $afterId = null): void
    {
        DB::transaction(function () use ($task, $statusId, $beforeId, $afterId): void {
            /** @var list<int> $ids */
            $ids = $this->siblings($task->project_id, $statusId, null)
                ->whereKeyNot($task->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $index = $this->insertionIndex($ids, $beforeId, $afterId);
            array_splice($ids, $index, 0, [$task->id]);

            if ($task->status_id !== $statusId) {
                $task->status_id = $statusId;
                $task->position = $index;
                $task->save();
            }

            $this->renumber($ids);
            $task->position = $index;
            $task->syncOriginalAttribute('position');
        });
    }

    /**
     * Renumera en ese orden (solo las filas que cambian, sin tocar updated_at).
     *
     * @param  list<int>  $ids
     */
    public function renumber(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $current = Task::query()->whereKey($ids)->pluck('position', 'id');

        foreach ($ids as $position => $id) {
            if ((int) ($current[$id] ?? -1) !== $position) {
                Task::query()->whereKey($id)->toBase()->update(['position' => $position]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function insertionIndex(array $ids, ?int $beforeId, ?int $afterId): int
    {
        if ($beforeId !== null) {
            $index = array_search($beforeId, $ids, true);

            if ($index === false) {
                throw ValidationException::withMessages(['before_id' => __('tasks.errors.position_column')]);
            }

            return $index;
        }

        if ($afterId !== null) {
            $index = array_search($afterId, $ids, true);

            if ($index === false) {
                throw ValidationException::withMessages(['after_id' => __('tasks.errors.position_column')]);
            }

            return $index + 1;
        }

        return count($ids);
    }

    /**
     * @return Builder<Task>
     */
    private function siblings(int $projectId, int $statusId, ?int $parentId): Builder
    {
        $query = Task::query();

        if ($parentId !== null) {
            return $query->where('parent_task_id', $parentId);
        }

        return $query->where('project_id', $projectId)
            ->where('status_id', $statusId)
            ->whereNull('parent_task_id');
    }
}
