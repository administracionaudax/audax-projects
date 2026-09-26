<?php

namespace App\Domain\Admin;

use App\Enums\TaskStatusCategory;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estados de tarea (SPEC §4.3 y §14). Invariantes que mantiene:
 * - exactamente un estado por defecto, y nunca de categoría «done» (sería el estado inicial de
 *   las tareas nuevas),
 * - siempre queda al menos un estado de categoría «todo» y otro de «done»,
 * - completed_at de las tareas sigue a la categoría de su estado (Task::booted lo hace tarea a
 *   tarea; aquí, al cambiar la categoría o al mover tareas de estado, con una actualización masiva),
 * - un estado con tareas solo se borra moviéndolas antes a otro (en la misma transacción), al
 *   final de la columna de destino de cada proyecto.
 * Las actualizaciones masivas de tareas dejan una entrada en la auditoría con el resumen.
 */
final class TaskStatusCatalog
{
    public function __construct(private readonly Reorderer $reorderer) {}

    /**
     * @param  array{name: string, color: string, category: string, is_default: bool}  $data
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $data): TaskStatus
    {
        return DB::transaction(function () use ($data): TaskStatus {
            $this->lockAll();
            $category = TaskStatusCategory::from($data['category']);

            if ($data['is_default'] && $category === TaskStatusCategory::Done) {
                throw ValidationException::withMessages(['is_default' => __('admin.statuses.errors.default_done')]);
            }

            $status = new TaskStatus([
                'name' => $data['name'],
                'color' => $data['color'],
                'category' => $category,
                'position' => $this->reorderer->next(TaskStatus::query()),
                'is_default' => false,
            ]);
            $status->save();

            // Si no hubiera ninguno por defecto (catálogo vacío), lo es el primero que no sea «done».
            $noDefault = ! TaskStatus::query()->where('is_default', true)->exists();

            if ($data['is_default'] || ($noDefault && $category !== TaskStatusCategory::Done)) {
                $this->makeDefault($status);
            }

            return $status;
        });
    }

    /**
     * @param  array{name: string, color: string, category: string, is_default: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, TaskStatus $status, array $data): TaskStatus
    {
        return DB::transaction(function () use ($actor, $status, $data): TaskStatus {
            $this->lockAll();
            /** @var TaskStatus $current */
            $current = TaskStatus::query()->findOrFail($status->id);
            $before = $current->category;
            $after = TaskStatusCategory::from($data['category']);

            if ($current->is_default && ! $data['is_default']) {
                throw ValidationException::withMessages(['is_default' => __('admin.statuses.errors.needs_default')]);
            }

            // Aquí, si ya era el de por defecto, sigue siéndolo (la regla anterior lo garantiza).
            if ($data['is_default'] && $after === TaskStatusCategory::Done) {
                throw ValidationException::withMessages(['category' => __('admin.statuses.errors.default_done')]);
            }

            if ($before !== $after) {
                $this->assertCategoriesRemain(excluding: $current, adding: $after);
            }

            $current->fill(['name' => $data['name'], 'color' => $data['color'], 'category' => $after])->save();

            if ($data['is_default'] && ! $current->is_default) {
                $this->makeDefault($current);
            }

            if (($before === TaskStatusCategory::Done) !== ($after === TaskStatusCategory::Done)) {
                $changed = $this->syncCompletedAt([$current->id], $after === TaskStatusCategory::Done);
                $this->audit($actor, $current, 'task_status.category_changed', [
                    'from' => $before->value,
                    'to' => $after->value,
                    'tasks' => $changed,
                ]);
            }

            $status->setRawAttributes($current->getAttributes(), true);

            return $current;
        });
    }

    /**
     * Borra un estado. Si tiene tareas, se mueven a $replacement (obligatorio en ese caso).
     *
     * @return int Tareas movidas.
     *
     * @throws ValidationException
     */
    public function delete(User $actor, TaskStatus $status, ?TaskStatus $replacement): int
    {
        return DB::transaction(function () use ($actor, $status, $replacement): int {
            $this->lockAll();
            /** @var TaskStatus $current */
            $current = TaskStatus::query()->findOrFail($status->id);

            if ($current->is_default) {
                throw ValidationException::withMessages(['status' => __('admin.statuses.errors.delete_default')]);
            }

            $this->assertCategoriesRemain(excluding: $current);

            $tasks = Task::withTrashed()->where('status_id', $current->id)->count();
            $moved = 0;

            if ($tasks > 0) {
                if ($replacement === null) {
                    throw ValidationException::withMessages(['replacement_status_id' => __('admin.statuses.errors.replacement_required', ['count' => $tasks])]);
                }

                if ($replacement->id === $current->id) {
                    throw ValidationException::withMessages(['replacement_status_id' => __('admin.statuses.errors.replacement_same')]);
                }

                $this->appendToColumns($current, $replacement);
                $moved = Task::withTrashed()->where('status_id', $current->id)->update(['status_id' => $replacement->id]);

                // Las movidas siguen a la categoría del estado nuevo: solo cambia si cruzan «done». Las
                // que ya estaban en el estado de reemplazo cumplen el invariante y no se tocan.
                if ($current->isDone() !== $replacement->isDone()) {
                    $this->syncCompletedAt([$replacement->id], $replacement->isDone());
                }

                $this->audit($actor, $replacement, 'task_status.replaced', [
                    'deleted' => $current->name,
                    'replacement' => $replacement->name,
                    'tasks' => $moved,
                ]);
            }

            $current->delete();

            return $moved;
        });
    }

    public function move(TaskStatus $status, string $direction): bool
    {
        return $this->reorderer->move(TaskStatus::query()->ordered(), $status, $direction);
    }

    private function makeDefault(TaskStatus $status): void
    {
        TaskStatus::query()->whereKeyNot($status->id)->where('is_default', true)->update(['is_default' => false]);
        $status->is_default = true;
        $status->save();
    }

    /**
     * Sin $excluding, ¿queda al menos un «todo» y un «done»? Opcionalmente contando $adding.
     *
     * @throws ValidationException
     */
    private function assertCategoriesRemain(TaskStatus $excluding, ?TaskStatusCategory $adding = null): void
    {
        $remaining = TaskStatus::query()->whereKeyNot($excluding->id)->pluck('category')
            ->map(fn (TaskStatusCategory|string $category): string => $category instanceof TaskStatusCategory ? $category->value : $category)
            ->all();

        if ($adding !== null) {
            $remaining[] = $adding->value;
        }

        foreach ([TaskStatusCategory::Todo, TaskStatusCategory::Done] as $required) {
            if (! in_array($required->value, $remaining, true)) {
                throw ValidationException::withMessages([
                    'category' => __('admin.statuses.errors.min_categories'),
                ]);
            }
        }
    }

    /**
     * Antes de mover las tareas de $from a $to, las coloca al final de la columna $to de cada
     * proyecto (position = position + máximo de esa columna + 1), conservando su orden relativo:
     * así el tablero no mezcla ni repite posiciones. Mientras se ejecuta, las tareas movidas aún
     * están en $from y la subconsulta solo lee las de $to (igual en SQLite y PostgreSQL).
     */
    private function appendToColumns(TaskStatus $from, TaskStatus $to): void
    {
        DB::update(
            'UPDATE tasks SET position = position + COALESCE(('
            .'SELECT MAX(column_tasks.position) + 1 FROM tasks AS column_tasks'
            .' WHERE column_tasks.project_id = tasks.project_id AND column_tasks.status_id = ?'
            .'), 0) WHERE status_id = ?',
            [$to->id, $from->id],
        );
    }

    /**
     * completed_at de las tareas de esos estados: now() si pasan a «done» (solo las que no lo
     * tenían) o null si dejan de estarlo. Actualización masiva (sin eventos por tarea).
     *
     * @param  list<int>  $statusIds
     */
    private function syncCompletedAt(array $statusIds, bool $done): int
    {
        $query = Task::withTrashed()->whereIn('status_id', $statusIds);

        return $done
            ? $query->whereNull('completed_at')->update(['completed_at' => now()])
            : $query->whereNotNull('completed_at')->update(['completed_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(User $actor, TaskStatus $status, string $event, array $properties): void
    {
        activity('task_statuses')
            ->causedBy($actor)
            ->performedOn($status)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }

    private function lockAll(): void
    {
        TaskStatus::query()->lockForUpdate()->get(['id']);
    }
}
