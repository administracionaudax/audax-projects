<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyEligibility;
use App\Enums\WeeklyExemptionReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\StoreWeeklyExemptionRequest;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Exenciones de la semana activa (F-038, F-053, F-097 y F-098, D-151):
 * - store: quien gestiona exime a mano a alguien que participa esa semana, con una nota (sustituye
 *   una renuncia anterior),
 * - waive: la propia persona se quita la exención para poder escribir (F-053): si es por una
 *   ausencia, queda una renuncia (waived); si es manual, se borra,
 * - destroy: quitar una exención manual (quien gestiona o la propia persona) o deshacer una renuncia.
 * La exención por ausencia de la semana activa no tiene fila: se calcula al vuelo (WeeklyEligibility).
 */
class WeeklyExemptionController extends Controller
{
    public function store(StoreWeeklyExemptionRequest $request, WeeklyCycle $cycle, WeeklyEligibility $eligibility): RedirectResponse
    {
        $person = User::query()->find($request->integer('user_id'));

        if ($person === null || ! $eligibility->rosterForUser($cycle, $person)->participates($person->id)) {
            throw ValidationException::withMessages(['user_id' => __('weeklies.validation.person')]);
        }

        WeeklyExemption::query()->updateOrCreate(
            ['weekly_cycle_id' => $cycle->id, 'user_id' => $person->id],
            [
                'reason' => WeeklyExemptionReason::Manual,
                'absence_id' => null,
                'note' => $request->filled('note') ? trim((string) $request->input('note')) : null,
                'created_by' => $request->user()?->id,
            ],
        );

        MyWeeklyStatus::forget($person->id, $cycle->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.exempted', ['name' => $person->name])]);

        return back();
    }

    public function waive(Request $request, WeeklyCycle $cycle, WeeklyEligibility $eligibility): RedirectResponse
    {
        Gate::authorize('waive', [WeeklyExemption::class, $cycle]);

        /** @var User $user */
        $user = $request->user();
        $roster = $eligibility->rosterForUser($cycle, $user);

        if (! $roster->isExempt($user->id)) {
            throw ValidationException::withMessages(['exemption' => __('weeklies.validation.not_exempt')]);
        }

        if ($roster->reasonFor($user->id) === WeeklyExemptionReason::Manual) {
            WeeklyExemption::query()->where('weekly_cycle_id', $cycle->id)->where('user_id', $user->id)->delete();
        } else {
            WeeklyExemption::query()->updateOrCreate(
                ['weekly_cycle_id' => $cycle->id, 'user_id' => $user->id],
                ['reason' => WeeklyExemptionReason::Waived, 'absence_id' => $roster->absenceFor($user->id), 'note' => null, 'created_by' => $user->id],
            );
        }

        MyWeeklyStatus::forget($user->id, $cycle->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.waived')]);

        return back();
    }

    public function destroy(WeeklyCycle $cycle, WeeklyExemption $exemption): RedirectResponse
    {
        abort_unless($exemption->weekly_cycle_id === $cycle->id, 404);
        Gate::authorize('delete', $exemption);

        $exemption->delete();
        MyWeeklyStatus::forget($exemption->user_id, $cycle->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __($exemption->reason === WeeklyExemptionReason::Waived ? 'weeklies.flash.waiver_undone' : 'weeklies.flash.exemption_removed')]);

        return back();
    }
}
