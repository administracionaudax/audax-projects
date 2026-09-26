<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Palette;
use App\Domain\Admin\TaskStatusCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveRequest;
use App\Http\Requests\Admin\TaskStatusRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\Admin\TaskStatusRowResource;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estados de tarea (SPEC §4.3 y §14). Las reglas (un estado por defecto, categorías mínimas,
 * reemplazo al borrar y completed_at) están en TaskStatusCatalog. Gate manage-settings.
 */
class TaskStatusController extends Controller
{
    public function __construct(private readonly TaskStatusCatalog $catalog) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $statuses = TaskStatus::query()
            ->withCount(['tasks' => fn ($query) => $query->withTrashed()])
            ->ordered()
            ->get();

        return Inertia::render('admin/statuses/index', [
            'statuses' => ResourceProps::list(TaskStatusRowResource::collection($statuses), $request),
            'palette' => Palette::COLORS,
        ]);
    }

    public function store(TaskStatusRequest $request): RedirectResponse
    {
        $this->catalog->create($this->actor($request), $request->statusData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.statuses.created')]);

        return back();
    }

    public function update(TaskStatusRequest $request, TaskStatus $taskStatus): RedirectResponse
    {
        $this->catalog->update($this->actor($request), $taskStatus, $request->statusData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.statuses.updated')]);

        return back();
    }

    public function move(MoveRequest $request, TaskStatus $taskStatus): RedirectResponse
    {
        $this->catalog->move($taskStatus, $request->direction());

        return back();
    }

    public function destroy(Request $request, TaskStatus $taskStatus): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $data = $request->validate([
            'replacement_status_id' => ['nullable', 'integer', Rule::exists(TaskStatus::class, 'id')],
        ], [], ['replacement_status_id' => __('admin.attributes.replacement')]);

        $replacement = isset($data['replacement_status_id'])
            ? TaskStatus::query()->find((int) $data['replacement_status_id'])
            : null;

        $moved = $this->catalog->delete($this->actor($request), $taskStatus, $replacement);

        Inertia::flash('toast', ['type' => 'success', 'message' => $moved > 0
            ? trans_choice('admin.statuses.deleted_moved', $moved, ['name' => $taskStatus->name, 'count' => $moved, 'replacement' => $replacement->name ?? ''])
            : __('admin.statuses.deleted', ['name' => $taskStatus->name]),
        ]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
