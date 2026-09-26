<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Palette;
use App\Domain\Admin\Reorderer;
use App\Domain\Admin\TaskTypeIcons;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveRequest;
use App\Http\Requests\Admin\TaskTypeRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\Admin\TaskTypeRowResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tipos de tarea (SPEC §4.3 y §14): crear, editar, ordenar (subir y bajar) y borrar. Un tipo que ya
 * usa alguna tarea no se borra: se desactiva (deja de ofrecerse, pero las tareas lo conservan).
 * Gate manage-settings.
 */
class TaskTypeController extends Controller
{
    public function __construct(private readonly Reorderer $reorderer) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $types = TaskType::query()
            ->withCount(['tasks' => fn ($query) => $query->withTrashed()])
            ->ordered()
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/task-types/index', [
            'taskTypes' => ResourceProps::list(TaskTypeRowResource::collection($types), $request),
            'departments' => ResourceProps::list(DepartmentResource::collection(Department::query()->orderBy('name')->get()), $request),
            'palette' => Palette::COLORS,
            'icons' => TaskTypeIcons::ICONS,
        ]);
    }

    public function store(TaskTypeRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            TaskType::query()->create([
                ...$request->typeData(),
                'position' => $this->reorderer->next(TaskType::query()),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.task_types.created')]);

        return back();
    }

    public function update(TaskTypeRequest $request, TaskType $taskType): RedirectResponse
    {
        $taskType->fill($request->typeData())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.task_types.updated')]);

        return back();
    }

    public function move(MoveRequest $request, TaskType $taskType): RedirectResponse
    {
        $this->reorderer->move(TaskType::query()->ordered()->orderBy('id'), $taskType, $request->direction());

        return back();
    }

    /**
     * Borra un tipo sin tareas. Si alguna tarea lo usa, lo desactiva y lo explica.
     */
    public function destroy(TaskType $taskType): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $inUse = Task::withTrashed()->where('task_type_id', $taskType->id)->exists();

        if ($inUse) {
            $taskType->is_active = false;
            $taskType->save();

            Inertia::flash('toast', ['type' => 'info', 'message' => __('admin.task_types.deactivated_in_use', ['name' => $taskType->name])]);

            return back();
        }

        $taskType->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.task_types.deleted', ['name' => $taskType->name])]);

        return back();
    }
}
