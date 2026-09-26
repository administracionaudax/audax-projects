<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\TaskMover;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\MoveTaskRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Mover una tarea (con sus subtareas) a otro proyecto (SPEC §6). Hace falta poder editarla y poder
 * crear tareas en el destino. Las horas se quedan donde estaban. Al terminar se abre la tarea en
 * su nuevo proyecto.
 */
class TaskMoveController extends Controller
{
    use RedirectsToTaskPanel;

    public function __construct(private readonly TaskMover $mover) {}

    public function __invoke(MoveTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        /** @var User $user */
        $user = $request->user();
        $target = Project::query()->findOrFail($request->integer('project_id'));

        if (Gate::forUser($user)->denies('create', [Task::class, $target])) {
            throw ValidationException::withMessages(['project_id' => __('tasks.errors.move_forbidden')]);
        }

        $bankId = $request->filled('hour_bank_id') ? $request->integer('hour_bank_id') : null;

        $this->mover->move($task, $target, $bankId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.moved', ['project' => $target->name])]);

        return redirect()->to($this->withTaskParam("/proyectos/{$target->id}/tareas", $task->id));
    }
}
