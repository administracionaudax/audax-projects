<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\TimeEntryWriter;
use App\Http\Requests\Time\TimeEntryRequest;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrada manual y hoja semanal (SPEC §7): crear, editar y borrar SIEMPRE con TimeEntryWriter,
 * que aplica las reglas de imputación y la política de las bolsas. Los avisos no bloqueantes
 * (tarea completada, jornada superada, exceso) vuelven como `time_warnings`.
 */
class TimeEntryController extends TimeController
{
    public function __construct(
        private readonly TimeEntryWriter $writer,
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

        $result = $this->writer->create($actor, $request->toData($actor));

        $this->toast(Messages::get('time.flash.entry_created', [
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

        $this->toast(Messages::get('time.flash.entry_updated', [
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
        $this->toast(Messages::get('time.flash.entry_deleted'));

        return back();
    }

    private function task(int $id): Task
    {
        /** @var Task $task */
        $task = Task::query()->withTrashed()->findOrFail($id);
        $this->authorize('view', $task);

        return $task;
    }
}
