<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\TaskWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\Tasks\TaskPanel;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Alta (creación rápida en línea y subtareas), edición desde el panel, borrado y el enlace
 * /tareas/{task}, que abre la tarea en su proyecto (sin página propia, SPEC §6).
 */
class TaskController extends Controller
{
    use RedirectsToTaskPanel;

    public function __construct(private readonly TaskWriter $writer) {}

    /**
     * /tareas/{task} → /proyectos/{project}/tareas?tarea={task}.
     */
    public function show(Task $task): RedirectResponse
    {
        Gate::authorize('view', $task);

        return redirect()->to($this->withTaskParam("/proyectos/{$task->project_id}/tareas", $task->id));
    }

    public function store(StoreTaskRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Task::class, $project]);

        /** @var User $user */
        $user = $request->user();

        $this->writer->create($user, $project, $request->validated());

        return back();
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        /** @var User $user */
        $user = $request->user();

        $this->writer->update($user, $task, $request->validated());

        return back();
    }

    /**
     * Borrado lógico (SoftDeletes) de la tarea y sus subtareas. Nada con horas se borra (D-037):
     * si la tarea o sus subtareas tienen horas, o un temporizador en marcha, se explica por qué.
     */
    public function destroy(Request $request, Task $task, TaskPanel $panel): RedirectResponse
    {
        // Primero, si puede tocar la tarea; después, si se puede borrar (con un mensaje claro).
        Gate::authorize('update', $task);

        $blocked = $panel->deleteBlockedReason($task);

        if ($blocked !== null) {
            $message = __("tasks.errors.{$blocked}", ['task' => $task->title]);
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['task' => $message]);
        }

        Gate::authorize('delete', $task);

        DB::transaction(function () use ($task): void {
            foreach ($task->subtasks()->get() as $subtask) {
                $subtask->delete();
            }

            $task->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.deleted')]);

        return $this->backWithPanel($task->parent_task_id, "/proyectos/{$task->project_id}/tareas");
    }
}
