<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Tasks\TaskWriter;
use App\Domain\Weeklies\Ai\AiDailyLimitReached;
use App\Domain\Weeklies\Tasks\TaskNotes;
use App\Domain\Weeklies\Tasks\TaskSuggester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\AcceptTaskSuggestionsRequest;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tareas de «Mi espacio» (F-055 a F-063, D-151, D-203 y D-204):
 * - tareas sugeridas por IA a partir de la última weekly cerrada (Job en la cola `ai`, F-062): se
 *   piden, se revisan y solo entonces se crean con TaskWriter (o se descartan),
 * - archivado personal (F-057): ocultar una tarea de mi lista, sin tocarla para los demás,
 * - notas (F-060): la descripción de la tarea en texto plano, con autoguardado.
 * Crear, editar, marcar hecha y borrar usan las rutas de siempre de las tareas (tasks.*), con
 * TaskPolicy y TaskWriter.
 */
class MySpaceTaskController extends Controller
{
    /** Pedir sugerencias de tareas a la IA (Job en la cola `ai`, F-062). */
    public function suggest(Request $request, TaskSuggester $suggester): JsonResponse|RedirectResponse
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $previous = $suggester->find($user);
        $busy = $previous !== null && TaskSuggester::isBusy($previous);
        try {
            $batch = $suggester->request($user);
        } catch (AiDailyLimitReached $e) {
            return ClientInsightsController::limitReached($request, $e);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['suggestions' => TaskSuggester::present($batch)], 202);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($busy ? 'weeklies.tasks.already_running' : 'weeklies.tasks.requested')]);

        return back();
    }

    /** Crear las propuestas revisadas (y descartar otras a la vez). */
    public function accept(AcceptTaskSuggestionsRequest $request, TaskSuggester $suggester): RedirectResponse
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $batch = $suggester->find($user);

        if ($batch === null || TaskSuggester::isBusy($batch)) {
            throw ValidationException::withMessages(['tasks' => __('weeklies.tasks.suggestion_gone')]);
        }

        $created = $suggester->accept($user, $batch, $request->accepted(), $request->dismissed());

        Inertia::flash('toast', ['type' => 'success', 'message' => $created === []
            ? __('weeklies.tasks.dismissed')
            : trans_choice('weeklies.tasks.created', count($created), ['count' => count($created)])]);

        return back();
    }

    /** Descartar propuestas (?keys[]=…) o toda la tanda. */
    public function dismiss(Request $request, TaskSuggester $suggester): RedirectResponse
    {
        Gate::authorize('use-weeklies');

        $keys = $request->validate(['keys' => ['sometimes', 'array', 'max:'.TaskSuggester::MAX_ITEMS], 'keys.*' => ['string', 'max:64']])['keys'] ?? null;

        /** @var User $user */
        $user = $request->user();
        $batch = $suggester->find($user);

        if ($batch !== null && ! TaskSuggester::isBusy($batch)) {
            $suggester->remove($batch, $keys === null ? null : array_values(array_map(strval(...), $keys)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.tasks.dismissed')]);

        return back();
    }

    public function archive(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('use-weeklies');
        Gate::authorize('view', $task);

        /** @var User $user */
        $user = $request->user();
        TaskArchive::query()->firstOrCreate(['user_id' => $user->id, 'task_id' => $task->id], ['archived_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.tasks.archived', ['task' => $task->title])]);

        return back();
    }

    public function unarchive(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('use-weeklies');
        Gate::authorize('view', $task);

        /** @var User $user */
        $user = $request->user();
        TaskArchive::query()->where('user_id', $user->id)->where('task_id', $task->id)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.tasks.unarchived', ['task' => $task->title])]);

        return back();
    }

    /**
     * Guardar las notas (la descripción en texto plano, F-060). Una descripción con formato no se
     * sobrescribe desde aquí: se edita en la tarea.
     */
    public function notes(Request $request, Task $task, TaskWriter $writer): JsonResponse|RedirectResponse
    {
        Gate::authorize('use-weeklies');
        Gate::authorize('update', $task);

        $notes = (string) ($request->validate(['notes' => ['present', 'nullable', 'string', 'max:'.TaskNotes::MAX_LENGTH]])['notes'] ?? '');

        if (! TaskNotes::isPlain($task->description)) {
            throw ValidationException::withMessages(['notes' => __('weeklies.tasks.errors.rich_notes')]);
        }

        /** @var User $user */
        $user = $request->user();
        $task = $writer->update($user, $task, ['description' => TaskNotes::toHtml($notes)]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['task' => ['id' => $task->id, 'notes' => TaskNotes::toPlain($task->description)]]);
        }

        return back();
    }
}
