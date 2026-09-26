<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\TaskNotifier;
use App\Domain\Tasks\TaskWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\BulkUpdateTasksRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Acciones masivas de la lista (SPEC §6): estado, responsable, fechas o bolsa de varias tareas.
 * Todo o nada: si una tarea no admite el cambio, no cambia ninguna y se dice cuál falla.
 * La bolsa solo se cambia en las tareas raíz (las subtareas usan la de su padre, D-037); las horas
 * ya imputadas nunca se mueven.
 */
class TaskBulkController extends Controller
{
    public function __construct(
        private readonly TaskWriter $writer,
        private readonly TaskNotifier $notifier,
    ) {}

    public function __invoke(BulkUpdateTasksRequest $request, Project $project): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $request->ids();
        $changes = $request->changes();

        $tasks = Task::query()->whereKey($ids)->where('project_id', $project->id)->orderBy('id')->get();

        if ($tasks->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => __('tasks.errors.bulk_not_found')]);
        }

        foreach ($tasks as $task) {
            $task->setRelation('project', $project);
            Gate::authorize('update', $task);
        }

        $skippedSubtaskBank = $this->notifier->capture(fn (): bool => DB::transaction(function () use ($tasks, $changes, $user): bool {
            $skipped = false;

            foreach ($tasks as $task) {
                $taskChanges = $changes;

                if ($task->isSubtask() && array_key_exists('hour_bank_id', $taskChanges)) {
                    $taskChanges = Arr::except($taskChanges, ['hour_bank_id']);
                    $skipped = true;
                }

                if ($taskChanges === []) {
                    continue;
                }

                try {
                    $this->writer->update($user, $task, $taskChanges);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['ids' => __('tasks.errors.bulk_task', [
                        'task' => $task->title,
                        'message' => collect($exception->errors())->flatten()->first(),
                    ])]);
                }
            }

            return $skipped;
        }));

        $message = trans_choice('tasks.flash.bulk_updated', $tasks->count(), ['count' => $tasks->count()]);

        if ($skippedSubtaskBank) {
            $message .= ' '.__('tasks.flash.bulk_subtasks_bank');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
