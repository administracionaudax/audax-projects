<?php

namespace App\Domain\Schedule;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dependencias fin-inicio (SPEC §6.1, D-056): solo entre tareas del mismo proyecto, sin enlazar
 * una tarea consigo misma y sin ciclos. Enlazar dos veces lo mismo no duplica. Los enlaces de un
 * mismo proyecto se hacen de uno en uno (D-090: la fila del proyecto queda bloqueada durante la
 * comprobación del ciclo y el alta).
 */
final class DependencyService
{
    /**
     * @throws ValidationException
     */
    public function link(Task $predecessor, Task $successor, ?User $actor = null): TaskDependency
    {
        if ($predecessor->id === $successor->id) {
            throw ValidationException::withMessages(['successor_task_id' => __('schedule.errors.self')]);
        }

        if ($predecessor->project_id !== $successor->project_id) {
            throw ValidationException::withMessages(['successor_task_id' => __('schedule.errors.other_project')]);
        }

        return DB::transaction(function () use ($predecessor, $successor, $actor): TaskDependency {
            // Un enlace del proyecto a la vez: con dos simultáneos (A → B y B → A), cada uno buscaría
            // el ciclo sin ver el alta del otro (READ COMMITTED) y entre los dos lo crearían. Con la
            // fila del proyecto bloqueada, el segundo espera a que el primero termine y ya lo ve.
            $this->lockProject($predecessor->project_id);

            $existing = TaskDependency::query()
                ->where('predecessor_task_id', $predecessor->id)
                ->where('successor_task_id', $successor->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($this->wouldCreateCycle($predecessor->project_id, $predecessor->id, $successor->id)) {
                throw ValidationException::withMessages(['successor_task_id' => __('schedule.errors.cycle')]);
            }

            return TaskDependency::query()->create([
                'predecessor_task_id' => $predecessor->id,
                'successor_task_id' => $successor->id,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function unlink(TaskDependency $dependency): void
    {
        $dependency->delete();
    }

    /**
     * Bloquea la fila del proyecto hasta el final de la transacción en curso: SELECT … FOR UPDATE
     * en PostgreSQL (SQLite, que solo usan los tests, no bloquea filas y lo ignora).
     */
    private function lockProject(int $projectId): void
    {
        Project::query()->withoutGlobalScopes()->whereKey($projectId)->lockForUpdate()->value('id');
    }

    /**
     * ¿Crearía un ciclo añadir predecesora → sucesora? Sí, si desde la sucesora ya se llega a la
     * predecesora siguiendo las dependencias del proyecto (una consulta y búsqueda en memoria).
     */
    public function wouldCreateCycle(int $projectId, int $predecessorId, int $successorId): bool
    {
        $edges = [];
        foreach ($this->projectDependencies($projectId) as [$from, $to]) {
            $edges[$from][] = $to;
        }

        $queue = [$successorId];
        $seen = [$successorId => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $predecessorId) {
                return true;
            }
            foreach ($edges[$current] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: int}> pares [predecesora, sucesora], en el orden en que se crearon
     */
    public function projectDependencies(int $projectId): array
    {
        $pairs = [];
        $rows = TaskDependency::query()
            ->join('tasks as dep_pred', 'dep_pred.id', '=', 'task_dependencies.predecessor_task_id')
            ->where('dep_pred.project_id', $projectId)
            ->whereNull('dep_pred.deleted_at')
            // Siempre el mismo orden en cualquier motor (PostgreSQL no garantiza ninguno sin ORDER BY).
            ->orderBy('task_dependencies.id')
            ->get(['task_dependencies.predecessor_task_id', 'task_dependencies.successor_task_id']);

        foreach ($rows as $dependency) {
            $pairs[] = [$dependency->predecessor_task_id, $dependency->successor_task_id];
        }

        return $pairs;
    }
}
