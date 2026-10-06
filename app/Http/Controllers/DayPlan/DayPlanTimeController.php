<?php

namespace App\Http\Controllers\DayPlan;

use App\Domain\DayPlan\DayPlanTime;
use App\Domain\Time\Messages;
use App\Domain\Time\TimeEntryResult;
use App\Models\DayPlanItem;
use App\Models\Task;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Las horas de mis líneas del plan del día (docs/PLAN-CARGAS.md §6.1.4; D-254): ▶ desde la línea,
 * «Imputar lo previsto» (de una o de todas las del día), y ver y vincular mis horas del día. Todo con
 * DayPlanTime (TimerService y TimeEntryWriter debajo, con todas sus reglas).
 */
class DayPlanTimeController extends DayPlanController
{
    public function __construct(private readonly DayPlanTime $time) {}

    /** POST /dia/lineas/{item}/temporizador {task_id?, create_task?} */
    public function start(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $request->validate([
            'task_id' => ['nullable', 'integer'],
            'create_task' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $previous = $this->time->start($user, $item, $request->filled('task_id') ? $request->integer('task_id') : null, $request->boolean('create_task'));
        $task = (string) Task::query()->whereKey($item->fresh()?->task_id)->value('title');

        $this->toast($previous === []
            ? Messages::get('time.flash.timer_started', ['task' => $task])
            : Messages::get('time.flash.timer_started_previous', [
                'task' => $task,
                'minutes' => Duration::format(array_sum(array_map(fn (TimeEntryResult $result): int => $result->entry->minutes, $previous))),
                'previous' => (string) Task::query()->withTrashed()->whereKey($previous[0]->entry->task_id)->value('title'),
            ]));

        return back();
    }

    /** POST /dia/lineas/{item}/imputar-previsto */
    public function logPlanned(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        /** @var User $user */
        $user = $request->user();
        $result = $this->time->logPlanned($user, $item);
        $this->toast(__('day_plan.flash.logged', ['time' => Duration::format($result->entry->minutes), 'text' => $item->text]));
        $this->warnings([$result]);

        return back();
    }

    /** POST /dia/imputar-previsto {date} */
    public function logPlannedDay(Request $request): RedirectResponse
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        /** @var User $user */
        $user = $request->user();
        $result = $this->time->logPlannedDay($user, $request->string('date')->toString());

        if ($result['failed'] === []) {
            $this->toast(trans_choice('day_plan.flash.logged_many', $result['logged'], ['count' => $result['logged'], 'time' => Duration::format($result['minutes'])]));
        } else {
            $this->toast(__('day_plan.flash.logged_partial', [
                'logged' => $result['logged'],
                'failed' => implode(' ', array_map(fn (array $failure): string => "«{$failure['text']}»: {$failure['message']}", $result['failed'])),
            ]), 'warning');
        }

        return back();
    }

    /** GET /dia/lineas/{item}/entradas → {"entries": [...]} */
    public function entries(Request $request, DayPlanItem $item): JsonResponse
    {
        $this->authorize('update', $item);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['entries' => $this->time->linkable($user, $item)]);
    }

    /** POST /dia/lineas/{item}/vincular {entry_ids: []} */
    public function link(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $request->validate([
            'entry_ids' => ['present', 'array', 'max:100'],
            'entry_ids.*' => ['integer', 'distinct'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->input('entry_ids', []));
        $linked = $this->time->link($user, $item, $ids);
        $this->toast(trans_choice('day_plan.flash.linked', $linked, ['count' => $linked]));

        return back();
    }

    /**
     * Avisos no bloqueantes de la imputación (jornada superada, exceso…), como en el área de horas.
     *
     * @param  list<TimeEntryResult>  $results
     */
    private function warnings(array $results): void
    {
        $warnings = [];

        foreach ($results as $result) {
            foreach ($result->warningsArray() as $warning) {
                $warnings[$warning['message']] = $warning;
            }
        }

        if ($warnings !== []) {
            Inertia::flash('time_warnings', array_values($warnings));
        }
    }
}
