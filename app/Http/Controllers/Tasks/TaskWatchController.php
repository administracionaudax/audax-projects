<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Seguir o dejar de seguir una tarea (SPEC §6): los seguidores reciben los avisos de comentarios y
 * cambios de estado. Cualquier interno puede seguir las tareas que ve (D-021).
 */
class TaskWatchController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('view', $task);

        /** @var User $user */
        $user = $request->user();
        $task->watchers()->syncWithoutDetaching([$user->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.watching')]);

        return back();
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('view', $task);

        /** @var User $user */
        $user = $request->user();
        $task->watchers()->detach($user->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.flash.unwatched')]);

        return back();
    }
}
