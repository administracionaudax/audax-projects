<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\TimeEntryResult;
use App\Domain\Time\TimerService;
use App\Enums\DayPlanItemStatus;
use App\Http\Requests\Time\StartTimerRequest;
use App\Http\Requests\Time\StopTimerRequest;
use App\Models\ActiveTimer;
use App\Models\DayPlanItem;
use App\Models\Task;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Temporizador de la cabecera y de cada tarea (SPEC §7, D-035, D-036). Uno por usuario y siempre
 * el propio: quién puede imputar en qué tarea lo deciden TimeEntryRules (miembro del proyecto,
 * bolsa, departamento, semana abierta), que responden con errores de validación comprensibles
 * en lugar de un 403.
 */
class TimerController extends TimeController
{
    public function __construct(
        private readonly TimerService $timers,
    ) {}

    /**
     * POST /temporizador: inicia. Si había otro en marcha, lo imputa y lo cuenta en el aviso.
     */
    public function start(StartTimerRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Task $task */
        $task = Task::query()->withTrashed()->findOrFail($request->integer('task_id'));
        $this->authorize('view', $task);

        $previous = $this->timers->start($user, $task, $request->filled('description') ? $request->string('description')->toString() : null);

        if ($previous === []) {
            $this->toast(Messages::get('time.flash.timer_started', ['task' => $task->title]));
        } else {
            $this->toast(Messages::get('time.flash.timer_started_previous', [
                'task' => $task->title,
                'minutes' => Duration::format($this->minutes($previous)),
                'previous' => $this->taskTitle($previous),
            ]));
            $this->flashWarnings($previous);
        }

        return back();
    }

    /**
     * POST /temporizador/parar: para e imputa. Si la imputación no es válida (p. ej. bolsa `block`
     * sin saldo o semana enviada), el temporizador sigue en marcha y se devuelven los errores para
     * que la interfaz ofrezca ajustar la duración, cambiar de tarea o descartarlo.
     */
    public function stop(StopTimerRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $task = $request->filled('task_id') ? Task::query()->withTrashed()->findOrFail($request->integer('task_id')) : null;

        if ($task !== null) {
            $this->authorize('view', $task);
        }

        // Plan del día (D-254): la línea desde la que se arrancó, para preguntar si se da por hecha.
        $lineId = ActiveTimer::query()->whereKey($user->id)->value('day_plan_item_id');

        $results = $this->timers->stop(
            $user,
            $request->filled('minutes') ? $request->integer('minutes') : null,
            $task,
            $request->filled('description') ? $request->string('description')->toString() : null,
        );

        if ($results === []) {
            $this->toast(Messages::get('time.warnings.timer_too_short'), 'warning');
        } else {
            $this->toast(Messages::get('time.flash.timer_stopped', [
                'minutes' => Duration::format($this->minutes($results)),
                'task' => $this->taskTitle($results),
            ]));
            $this->flashWarnings($results);
        }

        // También si duró tan poco que no se ha imputado nada: la línea puede estar hecha igual.
        $this->promptDayPlanLine($lineId === null ? null : (int) $lineId);

        return back();
    }

    /**
     * Al parar el temporizador de una línea del plan del día aún pendiente: «¿Das por hecha la
     * línea?» (D-254). La pregunta la pinta la interfaz con la prop flash `day_plan_prompt`.
     */
    private function promptDayPlanLine(?int $lineId): void
    {
        if ($lineId === null) {
            return;
        }

        $line = DayPlanItem::query()->whereKey($lineId)->where('status', DayPlanItemStatus::Pending->value)->first(['id', 'text']);

        if ($line !== null) {
            Inertia::flash('day_plan_prompt', ['id' => $line->id, 'text' => $line->text]);
        }
    }

    /**
     * DELETE /temporizador: descarta sin imputar.
     */
    public function discard(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->timers->discard($user);
        $this->toast(Messages::get('time.flash.timer_discarded'), 'info');

        return back();
    }

    /**
     * @param  list<TimeEntryResult>  $results
     */
    private function minutes(array $results): int
    {
        return array_sum(array_map(fn (TimeEntryResult $result): int => $result->entry->minutes, $results));
    }

    /**
     * @param  list<TimeEntryResult>  $results
     */
    private function taskTitle(array $results): string
    {
        $taskId = $results[0]->entry->task_id;

        return (string) Task::query()->withTrashed()->whereKey($taskId)->value('title');
    }
}
