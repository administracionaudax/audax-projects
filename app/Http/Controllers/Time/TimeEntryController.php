<?php

namespace App\Http\Controllers\Time;

use App\Domain\DayPlan\DayPlanWriter;
use App\Domain\Time\Messages;
use App\Domain\Time\TimeEntryWriter;
use App\Http\Requests\Time\TimeEntryRequest;
use App\Models\DayPlanItem;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Entrada manual y hoja semanal (SPEC §7): crear, editar y borrar SIEMPRE con TimeEntryWriter,
 * que aplica las reglas de imputación y la política de las bolsas. Los avisos no bloqueantes
 * (tarea completada, jornada superada, exceso) vuelven como `time_warnings`.
 *
 * Con `quiet=1` (celdas de la hoja semanal, que se editan como una hoja de cálculo) no se envía el
 * aviso de éxito; los avisos no bloqueantes, sí.
 */
class TimeEntryController extends TimeController
{
    public function __construct(
        private readonly TimeEntryWriter $writer,
        private readonly DayPlanWriter $dayPlans,
    ) {}

    /**
     * POST /horas/entradas. En nombre de otra persona, lo autoriza TimeEntryPolicy::logTimeFor
     * dentro de las reglas (gestor en su proyecto, responsable de su equipo o admin).
     */
    public function store(TimeEntryRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $task = $this->task($request->integer('task_id'));
        $data = $request->toData($actor);
        $line = $this->dayPlanLine($data->userId, $data->dayPlanItemId);

        $result = $this->writer->create($actor, $data);

        // Plan del día (D-254): una línea sin tarea se queda con la de sus horas.
        if ($line !== null) {
            $this->dayPlans->adoptTask($line, $task);
        }

        $this->success($request, Messages::get('time.flash.entry_created', [
            'minutes' => Duration::format($result->entry->minutes),
            'task' => $task->title,
        ]));
        $this->flashWarnings([$result]);

        return back();
    }

    /**
     * PUT /horas/entradas/{entry}. Las bloqueadas, solo un admin (TimeEntryPolicy::update).
     */
    public function update(TimeEntryRequest $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        /** @var User $actor */
        $actor = $request->user();
        $task = $this->task($request->integer('task_id'));

        $result = $this->writer->update($actor, $entry, $request->toData($actor, $entry));

        $this->success($request, Messages::get('time.flash.entry_updated', [
            'minutes' => Duration::format($result->entry->minutes),
            'task' => $task->title,
        ]));
        $this->flashWarnings([$result]);

        return back();
    }

    /**
     * DELETE /horas/entradas/{entry}.
     */
    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        /** @var User $actor */
        $actor = $request->user();

        $this->writer->delete($actor, $entry);
        $this->success($request, Messages::get('time.flash.entry_deleted'));

        return back();
    }

    private function success(Request $request, string $message): void
    {
        if (! $request->boolean('quiet')) {
            $this->toast($message);
        }
    }

    /**
     * La línea del plan del día de las horas: de la persona de la entrada, sin pasar y en un día que
     * aún se puede cerrar (D-254).
     *
     * @throws ValidationException
     */
    private function dayPlanLine(int $userId, ?int $lineId): ?DayPlanItem
    {
        if ($lineId === null) {
            return null;
        }

        $line = DayPlanItem::query()->find($lineId);

        if ($line === null) {
            throw ValidationException::withMessages(['day_plan_item_id' => __('day_plan.errors.not_yours')]);
        }

        $this->dayPlans->assertLoggable(User::query()->findOrFail($userId), $line);

        return $line;
    }

    private function task(int $id): Task
    {
        /** @var Task $task */
        $task = Task::query()->withTrashed()->findOrFail($id);
        $this->authorize('view', $task);

        return $task;
    }
}
