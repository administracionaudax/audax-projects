<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyRuleViolation;
use App\Domain\Weeklies\WeeklySubmissionWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\SaveWeeklyDraftRequest;
use App\Http\Resources\Weeklies\WeeklySubmissionResource;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Mi weekly de una semana (F-044 a F-054), siempre con WeeklySubmissionWriter:
 * - draft (PUT, JSON): el borrador autoguardado con debounce (F-051); responde con la weekly
 *   guardada, sin recargar la página,
 * - submit (POST, Inertia): enviar o reenviar (F-052); se puede hasta el cierre, también fuera de
 *   plazo, y conserva la fecha del primer envío.
 * Una regla rota (semana cerrada, no le toca o exento) es un error de validación con su mensaje.
 */
class MyWeeklyController extends Controller
{
    public function draft(SaveWeeklyDraftRequest $request, WeeklyCycle $cycle, WeeklySubmissionWriter $writer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $submission = $writer->saveDraft($user, $cycle, $request->draft());
        } catch (WeeklyRuleViolation $violation) {
            throw ValidationException::withMessages(['entries' => $violation->userMessage()]);
        }

        return response()->json(['submission' => WeeklySubmissionResource::make($submission)]);
    }

    public function submit(SaveWeeklyDraftRequest $request, WeeklyCycle $cycle, WeeklySubmissionWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $first = ! $user->weeklySubmissions()->where('weekly_cycle_id', $cycle->id)->whereNotNull('submitted_at')->exists();

        try {
            $writer->submit($user, $cycle, $request->draft());
        } catch (WeeklyRuleViolation $violation) {
            throw ValidationException::withMessages(['entries' => $violation->userMessage()]);
        }

        MyWeeklyStatus::forget($user->id, $cycle);

        Inertia::flash('toast', ['type' => 'success', 'message' => __($first ? 'weeklies.flash.submitted' : 'weeklies.flash.resubmitted')]);

        return to_route('my-space.index', ['semana' => $cycle->id]);
    }
}
