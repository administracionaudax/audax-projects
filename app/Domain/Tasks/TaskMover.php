<?php

namespace App\Domain\Tasks;

use App\Domain\Reports\ReportCache;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskDependency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mover una tarea a otro proyecto (SPEC §6):
 * - solo tareas raíz: sus subtareas se mueven con ella (mismo proyecto y misma bolsa, D-037),
 * - la bolsa se elige de nuevo entre las abiertas del proyecto destino (obligatoria si usa bolsas),
 * - las horas ya imputadas NO se mueven: sus entradas conservan su proyecto y su bolsa,
 * - con un temporizador en marcha en la tarea o en sus subtareas no se mueve: al pararlo, esas
 *   horas se imputarían al proyecto y a la bolsa de destino,
 * - los adjuntos de la tarea, de sus subtareas y de sus comentarios pasan a la pestaña Archivos
 *   del proyecto destino (project_id desnormalizado; el fichero no cambia de sitio),
 * - las dependencias solo unen tareas del mismo proyecto (D-056): se quitan las que la tarea y
 *   sus subtareas tenían con tareas que se quedan en el proyecto de origen.
 * La autorización (editar la tarea y crear en el destino) la hace el controlador.
 */
final class TaskMover
{
    public function __construct(
        private readonly TaskWriter $writer,
        private readonly TaskPositions $positions,
    ) {}

    /**
     * @throws ValidationException
     */
    public function move(Task $task, Project $target, ?int $bankId): Task
    {
        if ($task->isSubtask()) {
            throw ValidationException::withMessages(['project_id' => __('tasks.errors.move_subtask')]);
        }

        if ($task->project_id === $target->id) {
            throw ValidationException::withMessages(['project_id' => __('tasks.errors.move_same_project')]);
        }

        if ($this->writer->hasRunningTimer($task)) {
            throw ValidationException::withMessages(['project_id' => __('tasks.errors.move_timer_running')]);
        }

        $bank = $this->writer->assertBank($target, $bankId);

        return DB::transaction(function () use ($task, $target, $bank): Task {
            $task->project_id = $target->id;
            $task->hour_bank_id = $bank?->id;
            $task->position = $this->positions->next($target->id, $task->status_id);
            $task->save();
            $task->setRelation('project', $target);

            $subtasks = $task->subtasks()->get();

            foreach ($subtasks as $subtask) {
                $subtask->project_id = $target->id;
                $subtask->hour_bank_id = $task->hour_bank_id;
                $subtask->save();
            }

            $taskIds = [$task->id, ...$subtasks->modelKeys()];

            Attachment::query()
                ->where(function (Builder $query) use ($taskIds): void {
                    $query->where(fn (Builder $tasks) => $tasks->where('attachable_type', (new Task)->getMorphClass())->whereIn('attachable_id', $taskIds))
                        ->orWhere(fn (Builder $comments) => $comments->where('attachable_type', (new TaskComment)->getMorphClass())
                            ->whereIn('attachable_id', TaskComment::query()->withTrashed()->select('id')->whereIn('task_id', $taskIds)));
                })
                ->update(['project_id' => $target->id]);

            TaskDependency::query()
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $from) => $from->whereIn('predecessor_task_id', $taskIds)->whereNotIn('successor_task_id', $taskIds))
                    ->orWhere(fn (Builder $to) => $to->whereIn('successor_task_id', $taskIds)->whereNotIn('predecessor_task_id', $taskIds)))
                ->delete();

            // La tarea y sus subtareas cambian de proyecto: la caché de informes, tras el commit (INT-03).
            ReportCache::bumpAfterCommit();

            return $task;
        });
    }
}
