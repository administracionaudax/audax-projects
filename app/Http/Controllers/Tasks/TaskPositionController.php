<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\TaskNotifier;
use App\Domain\Tasks\TaskPositions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\PositionTaskRequest;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Soltar una tarjeta en el kanban (SPEC §6): cambia el estado y la posición dentro de la columna.
 * Las posiciones de la columna quedan consecutivas (TaskPositions); al cambiar de estado se avisa a
 * los seguidores y el modelo mantiene completed_at.
 */
class TaskPositionController extends Controller
{
    public function __construct(
        private readonly TaskPositions $positions,
        private readonly TaskNotifier $notifier,
    ) {}

    public function __invoke(PositionTaskRequest $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        if ($task->isSubtask()) {
            throw ValidationException::withMessages(['status_id' => __('tasks.errors.move_subtask')]);
        }

        /** @var User $user */
        $user = $request->user();
        $statusId = $request->integer('status_id');
        $previousStatusId = $task->status_id;
        $beforeId = $request->filled('before_id') ? $request->integer('before_id') : null;
        $afterId = $request->filled('after_id') ? $request->integer('after_id') : null;

        $this->notifier->capture(function () use ($task, $user, $statusId, $previousStatusId, $beforeId, $afterId): void {
            $this->positions->place($task, $statusId, $beforeId, $afterId);

            if ($statusId !== $previousStatusId) {
                $task->loadMissing('project');
                $this->notifier->statusChanged($task, $user, TaskStatus::query()->findOrFail($statusId));
            }
        });

        return back();
    }
}
