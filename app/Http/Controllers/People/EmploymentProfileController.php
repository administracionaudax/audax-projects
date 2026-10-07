<?php

namespace App\Http\Controllers\People;

use App\Domain\People\PeopleAccess;
use App\Http\Controllers\Controller;
use App\Models\EmploymentProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Datos laborales de una persona (D-343; W-124 y W-125): fecha de alta y de baja y si está sujeta al
 * registro de jornada (si no, con un motivo: un socio que no es asalariado, por ejemplo); desde R2,
 * el tiempo parcial y la retención por litigio con su motivo (D-348). Los edita
 * quien tiene `manage-people` desde la ficha del usuario; cada cambio queda en la auditoría.
 */
class EmploymentProfileController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(PeopleAccess::managesAll($actor) && PeopleAccess::internalStaff($user), 403);

        $data = $request->validate([
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'subject_to_register' => ['required', 'boolean'],
            'register_exemption_reason' => ['nullable', 'required_if:subject_to_register,false', 'string', 'min:5', 'max:200'],
            'part_time' => ['sometimes', 'boolean'],
            'legal_hold' => ['sometimes', 'boolean'],
            'legal_hold_reason' => ['nullable', 'required_if:legal_hold,true', 'string', 'min:5', 'max:300'],
        ], [
            'legal_hold_reason.required_if' => __('people.employment.legal_hold_reason_required'),
            'legal_hold_reason.min' => __('people.employment.legal_hold_reason_required'),
            'termination_date.after_or_equal' => __('people.employment.termination_before_hire'),
            'register_exemption_reason.required_if' => __('people.employment.reason_required'),
            'register_exemption_reason.min' => __('people.employment.reason_required'),
        ], __('people.employment.attributes'));

        $profile = EmploymentProfile::query()->firstOrNew(['user_id' => $user->id]);
        $hold = array_key_exists('legal_hold', $data) ? (bool) $data['legal_hold'] : $profile->legal_hold;
        $profile->fill([
            'hire_date' => $data['hire_date'] ?? null,
            'termination_date' => $data['termination_date'] ?? null,
            'subject_to_register' => (bool) $data['subject_to_register'],
            'register_exemption_reason' => $data['subject_to_register'] ? null : trim((string) $data['register_exemption_reason']),
            'part_time' => array_key_exists('part_time', $data) ? (bool) $data['part_time'] : $profile->part_time,
            // Retención por litigio (G.5; D-348): mientras esté activa, nada de su registro se suprime.
            'legal_hold' => $hold,
            'legal_hold_reason' => $hold ? trim((string) ($data['legal_hold_reason'] ?? $profile->legal_hold_reason)) : null,
            'legal_hold_since' => $hold ? ($profile->legal_hold ? $profile->legal_hold_since : now()) : null,
            'legal_hold_by' => $hold ? ($profile->legal_hold ? $profile->legal_hold_by : $actor->id) : null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.flash.employment_saved')]);

        return back();
    }
}
