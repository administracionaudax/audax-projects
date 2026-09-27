<?php

namespace App\Http\Controllers\Planning;

use App\Domain\Admin\TextSearch;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /tareas/{task}/dependencias/candidatas?buscar= → {"tasks": DependencyCandidate[]}: tareas
 * del mismo proyecto con las que se puede enlazar la tarea desde su panel (D-056): todas menos
 * ella misma y las que están en la papelera. Primero las abiertas y por entrega. Solo quien puede
 * editarla (TaskPolicy::update), que es quien puede añadir dependencias. Los ciclos los rechaza
 * DependencyService al guardar.
 */
class DependencyCandidatesController extends Controller
{
    public const int LIMIT = 30;

    public function __invoke(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('update', $task);

        $request->validate(['buscar' => ['nullable', 'string', 'max:100']]);
        $search = trim($request->string('buscar')->toString());

        $tasks = Task::query()
            ->where('project_id', $task->project_id)
            ->whereKeyNot($task->id)
            ->when($search !== '', fn (Builder $query) => TextSearch::apply($query, $search, ['tasks.title']))
            ->with('parent:id,title')
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'project_id', 'parent_task_id', 'title', 'start_date', 'due_date', 'is_milestone', 'completed_at']);

        return response()->json([
            'tasks' => array_values($tasks->map(fn (Task $candidate): array => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'parent_title' => $candidate->parent?->title,
                'start_date' => $candidate->start_date?->toDateString(),
                'due_date' => $candidate->due_date?->toDateString(),
                'is_milestone' => $candidate->is_milestone,
                'is_completed' => $candidate->isCompleted(),
            ])->all()),
        ]);
    }
}
