<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Weeklies\WeeklyAway;
use App\Enums\AbsenceType;
use App\Enums\WeeklyAwayReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «Estoy fuera» de la Weekly (10.9b, D-228): como el estado de WeeklySync, con efecto inmediato.
 * - update: la propia persona (use-weeklies) o quien gestiona la Weekly a otra persona de la
 *   plantilla. Motivo (vacaciones o ausente/baja) y vuelta opcional (hoy o después). La propia
 *   persona puede pedir a la vez la ausencia (AbsenceService::request, que sigue su aprobación).
 * - destroy: «Vuelvo a estar disponible».
 */
class WeeklyAwayController extends Controller
{
    public function update(Request $request, User $user, WeeklyAway $away, AbsenceService $absences): RedirectResponse
    {
        $this->authorizeFor($request, $user);

        $today = WeeklyAway::today();
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(WeeklyAwayReason::class)],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'request_absence' => ['sometimes', 'boolean'],
        ], [
            'reason.required' => __('weeklies.validation.away_reason'),
            'reason.enum' => __('weeklies.validation.away_reason'),
            'until.date_format' => __('weeklies.validation.away_until'),
            'until.after_or_equal' => __('weeklies.validation.away_until'),
        ]);

        $reason = WeeklyAwayReason::from($validated['reason']);
        $until = $validated['until'] ?? null;
        /** @var User $actor */
        $actor = $request->user();
        $requested = false;

        if ($request->boolean('request_absence') && $actor->is($user)) {
            if ($until === null) {
                throw ValidationException::withMessages(['request_absence' => __('weeklies.validation.away_absence_needs_until')]);
            }

            try {
                $absences->request($actor, new AbsenceData(
                    type: $reason === WeeklyAwayReason::Vacation ? AbsenceType::Vacation : AbsenceType::Other,
                    startDate: $today,
                    endDate: $until,
                    notes: __('weeklies.away.absence_note'),
                ));
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['request_absence' => collect($e->errors())->flatten()->first() ?? __('weeklies.validation.away_absence_failed')]);
            }

            $requested = true;
        }

        $away->set($user, $reason, $until);

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            ! $actor->is($user) => __('weeklies.flash.away_other', ['name' => $user->name]),
            $requested => __('weeklies.flash.away_with_absence'),
            default => __('weeklies.flash.away'),
        }]);

        return back();
    }

    public function destroy(Request $request, User $user, WeeklyAway $away): RedirectResponse
    {
        $this->authorizeFor($request, $user);

        $away->clear($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->user()?->is($user)
            ? __('weeklies.flash.available')
            : __('weeklies.flash.available_other', ['name' => $user->name])]);

        return back();
    }

    /** La propia persona, o quien gestiona la Weekly a alguien activo que la escribe. */
    private function authorizeFor(Request $request, User $person): void
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->is($person)) {
            Gate::authorize('use-weeklies');

            return;
        }

        Gate::authorize('manage-weeklies');
        abort_unless($person->is_active && $person->writesWeeklies() && ! $person->isCollaborator(), 404);
    }
}
