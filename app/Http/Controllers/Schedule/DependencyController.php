<?php

namespace App\Http\Controllers\Schedule;

use App\Domain\Schedule\DependencyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreDependencyRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Crear y quitar dependencias fin-inicio (SPEC §6.1, D-056) desde el Gantt o el panel de tarea.
 * Hace falta poder editar las dos tareas (TaskPolicy::update). Responde con back() (Inertia).
 */
class DependencyController extends Controller
{
    public function __construct(private readonly DependencyService $dependencies) {}

    public function store(StoreDependencyRequest $request, Project $project): RedirectResponse
    {
        $tasks = Task::query()->where('project_id', $project->id)
            ->whereIn('id', [$request->integer('predecessor_task_id'), $request->integer('successor_task_id')])
            ->with('project')
            ->get()
            ->keyBy('id');

        $predecessor = $tasks->get($request->integer('predecessor_task_id'));
        $successor = $tasks->get($request->integer('successor_task_id'));
        abort_if($predecessor === null || $successor === null, 404);

        Gate::authorize('update', $predecessor);
        Gate::authorize('update', $successor);

        /** @var User $user */
        $user = $request->user();

        $this->dependencies->link($predecessor, $successor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('schedule.flash.linked', [
            'predecessor' => $predecessor->title,
            'successor' => $successor->title,
        ])]);

        return back();
    }

    public function destroy(TaskDependency $dependency): RedirectResponse
    {
        $dependency->load(['predecessor.project', 'successor.project']);
        abort_if($dependency->predecessor === null || $dependency->successor === null, 404);

        Gate::authorize('update', $dependency->predecessor);
        Gate::authorize('update', $dependency->successor);

        $this->dependencies->unlink($dependency);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('schedule.flash.unlinked')]);

        return back();
    }
}
