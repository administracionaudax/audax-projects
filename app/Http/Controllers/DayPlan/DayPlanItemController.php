<?php

namespace App\Http\Controllers\DayPlan;

use App\Domain\DayPlan\DayPlanCalendar;
use App\Domain\DayPlan\DayPlanTaskSuggestions;
use App\Domain\DayPlan\DayPlanWriter;
use App\Domain\Tasks\TaskWriter;
use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Enums\TaskStatusCategory;
use App\Http\Requests\DayPlan\DayPlanItemRequest;
use App\Models\DayPlanItem;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Escribir mi plan del día (docs/PLAN-CARGAS.md §4.2 y §4.4, D-250 y D-253): añadir, editar, borrar,
 * ordenar, cerrar (hecha, no hecha, pendiente), pasar a otro día, «Pasar a hoy» y «Marcar como no
 * hechas» las pendientes, la nota del día y añadir líneas desde mis tareas. Todo con DayPlanWriter
 * y solo en el plan propio (DayPlanItemPolicy).
 */
class DayPlanItemController extends DayPlanController
{
    public function __construct(private readonly DayPlanWriter $writer) {}

    /** POST /dia/lineas */
    public function store(DayPlanItemRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->lineData();

        $this->writer->add(
            $user,
            $request->string('date')->toString(),
            ['text' => $data['text'] ?? '', ...$data],
            $request->filled('after_id') ? $request->integer('after_id') : null,
        );

        return back();
    }

    /** PATCH /dia/lineas/{item} */
    public function update(DayPlanItemRequest $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        /** @var User $user */
        $user = $request->user();
        $this->writer->update($user, $item, $request->lineData());

        return back();
    }

    /** DELETE /dia/lineas/{item} */
    public function destroy(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        /** @var User $user */
        $user = $request->user();
        $this->writer->delete($user, $item);
        $this->toast(__('day_plan.flash.deleted'), 'info');

        return back();
    }

    /**
     * POST /dia/lineas/{item}/estado {status: pending|done|not_done, reason?, complete_task?}. Con
     * `complete_task`, si la línea tiene tarea y se puede editar, la tarea pasa a hecha (nunca al
     * revés ni en silencio, §4.4).
     */
    public function status(Request $request, DayPlanItem $item, TaskWriter $tasks): RedirectResponse
    {
        $this->authorize('update', $item);

        $request->validate([
            'status' => ['required', Rule::in([DayPlanItemStatus::Pending->value, DayPlanItemStatus::Done->value, DayPlanItemStatus::NotDone->value])],
            'reason' => ['nullable', 'string', 'max:'.DayPlanItem::TEXT_MAX],
            'complete_task' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $status = DayPlanItemStatus::from($request->string('status')->toString());
        $this->writer->setStatus($user, $item, $status, $request->filled('reason') ? $request->string('reason')->toString() : null);

        if ($status === DayPlanItemStatus::Done && $request->boolean('complete_task') && $item->task_id !== null) {
            $this->completeTask($user, $item->task_id, $tasks);
        }

        return back();
    }

    /** POST /dia/lineas/{item}/pasar {date} */
    public function carry(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        /** @var User $user */
        $user = $request->user();
        $date = $request->string('date')->toString();
        $this->writer->carry($user, $item, $date);
        $this->toast(__('day_plan.flash.carried', ['date' => $this->label($date)]));

        return back();
    }

    /** POST /dia/pendientes/pasar {ids: [], date?}: «Pasar a hoy» (todas o las elegidas). */
    public function carryPending(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.DayPlanWriter::MAX_ITEMS_PER_DAY],
            'ids.*' => ['integer', 'distinct'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $date = $request->filled('date') ? $request->string('date')->toString() : DayPlanCalendar::today()->toDateString();
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->input('ids'));
        $copies = $this->writer->carryMany($user, $ids, $date);
        $this->toast(trans_choice('day_plan.flash.carried_many', count($copies), ['count' => count($copies), 'date' => $this->label($date)]));

        return back();
    }

    /** POST /dia/pendientes/no-hechas {ids: []} */
    public function markPendingNotDone(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.DayPlanWriter::MAX_ITEMS_PER_DAY],
            'ids.*' => ['integer', 'distinct'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->input('ids'));
        $count = $this->writer->markManyNotDone($user, $ids);
        $this->toast(trans_choice('day_plan.flash.not_done_many', $count, ['count' => $count]), 'info');

        return back();
    }

    /** PUT /dia/orden {date, ids: []} */
    public function reorder(Request $request): RedirectResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'ids' => ['required', 'array', 'max:'.DayPlanWriter::MAX_ITEMS_PER_DAY],
            'ids.*' => ['integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->input('ids'));
        $this->writer->reorder($user, $request->string('date')->toString(), $ids);

        return back();
    }

    /** PUT /dia/nota {date, note} */
    public function note(Request $request): RedirectResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->writer->setNote($user, $request->string('date')->toString(), $request->input('note') === null ? null : $request->string('note')->toString());

        return back();
    }

    /**
     * POST /dia/desde-tareas {date, task_ids: []}: «Desde mis tareas» y «Añadir a mi día» de Mis
     * tareas. Una línea por tarea con su título (las que ya están ese día no se repiten).
     */
    public function fromTasks(Request $request): RedirectResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'task_ids' => ['required', 'array', 'min:1', 'max:30'],
            'task_ids.*' => ['integer', 'distinct'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $date = $request->string('date')->toString();
        /** @var list<int> $taskIds */
        $taskIds = array_map('intval', (array) $request->input('task_ids'));
        $present = DayPlanItem::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', '!=', DayPlanItemStatus::Carried->value)
            ->whereIn('task_id', $taskIds)
            ->pluck('task_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $added = 0;

        foreach (Task::query()->whereKey($taskIds)->orderBy('title')->get(['id', 'title', 'project_id']) as $task) {
            if (in_array($task->id, $present, true) || ! Gate::allows('view', $task)) {
                continue;
            }

            $this->writer->add($user, $date, ['text' => mb_substr($task->title, 0, DayPlanItem::TEXT_MAX), 'task_id' => $task->id], null, DayPlanItemOrigin::Task);
            $added++;
        }

        $this->toast($added === 0
            ? __('day_plan.flash.from_tasks_none')
            : trans_choice('day_plan.flash.from_tasks', $added, ['count' => $added, 'date' => $this->label($date)]), $added === 0 ? 'info' : 'success');

        return back();
    }

    /** GET /dia/tareas-sugeridas?fecha= → {"tasks": [...]}: el diálogo «Desde mis tareas». */
    public function suggestions(Request $request, DayPlanTaskSuggestions $suggestions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['tasks' => $suggestions->for($user, $this->date($request))]);
    }

    private function completeTask(User $user, int $taskId, TaskWriter $tasks): void
    {
        $task = Task::query()->with('project')->find($taskId);

        if ($task === null || $task->completed_at !== null || ! Gate::forUser($user)->allows('update', $task)) {
            return;
        }

        $done = TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->orderBy('position')->value('id');

        if ($done !== null) {
            $tasks->update($user, $task, ['status_id' => (int) $done]);
        }
    }

    /** «hoy», «mañana» o «el 14/10». */
    private function label(string $date): string
    {
        $today = DayPlanCalendar::today();

        return match ($date) {
            $today->toDateString() => __('day_plan.dates.today'),
            $today->addDay()->toDateString() => __('day_plan.dates.tomorrow'),
            default => __('day_plan.dates.on', ['date' => date('d/m', (int) strtotime($date))]),
        };
    }
}
