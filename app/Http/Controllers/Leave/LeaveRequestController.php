<?php

namespace App\Http\Controllers\Leave;

use App\Domain\Absences\AbsenceAdvisor;
use App\Domain\Absences\AbsenceCost;
use App\Domain\Absences\AbsenceRules;
use App\Domain\Absences\AbsenceService;
use App\Domain\Absences\AbsenceText;
use App\Domain\Absences\LeaveBalances;
use App\Domain\Absences\LeaveFormat;
use App\Http\Controllers\Absences\AbsencesController;
use App\Http\Requests\Absences\StoreAbsenceRequest;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lo nuevo de las solicitudes en R3 (Fase 11; D-364 y D-365), con el módulo `people`:
 * - POST /ausencias/simular (JSON): lo que costaría una solicitud, el saldo que quedaría, los avisos
 *   (antelación, preaviso, justificante…) y los errores que daría, sin guardar nada. El formulario
 *   lo pide mientras se rellena, como el «te quedarán…» de Woffu.
 * - POST /ausencias/{ausencia}/pedir-cancelacion: la persona pide cancelar una aprobada ya empezada.
 * - POST /ausencias/{ausencia}/cancelacion: quien aprueba acepta o rechaza esa petición.
 */
class LeaveRequestController extends AbsencesController
{
    public function simulate(StoreAbsenceRequest $request, AbsenceRules $rules, AbsenceCost $cost, LeaveBalances $balances, AbsenceAdvisor $advisor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->absenceData();
        $type = $data->leaveType;
        $errors = [];

        try {
            $rules->check($user, $user, $data, own: true);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        if ($type === null || $data->endDate < $data->startDate) {
            return response()->json(['cost' => null, 'balance' => null, 'warnings' => [], 'errors' => $errors]);
        }

        $days = $cost->days($user->id, $type, $data->startDate, $data->endDate, $data->partialMinutes);
        $total = AbsenceCost::total($days);
        $balance = null;

        if ($type->hasAllowance()) {
            $check = $balances->shortfall($user, $type, $days);
            $balance = [
                'available' => $check['available'],
                'after' => $check['available'] - $total,
                'available_label' => LeaveFormat::amount(max($check['available'], 0), $type->unit),
                'after_label' => LeaveFormat::amount($check['available'] - $total, $type->unit),
            ];
        }

        return response()->json([
            'cost' => ['amount' => $total, 'unit' => $type->unit->value, 'label' => LeaveFormat::amount($total, $type->unit)],
            'balance' => $balance,
            'warnings' => $advisor->warnings($user, $type, $data->startDate, $data->endDate, $data->partialMinutes, days: $days),
            'errors' => $errors,
        ]);
    }

    public function requestCancellation(Request $request, Absence $absence, AbsenceService $service): RedirectResponse
    {
        $this->authorize('requestCancellation', $absence);
        $request->validate(['reason' => ['required', 'string', 'max:2000']], [], (array) __('leave.attributes'));

        /** @var User $user */
        $user = $request->user();
        $absence = $service->requestCancellation($user, $absence, $request->string('reason')->toString());

        $this->toast(AbsenceText::get('leave.flash.cancellation_requested', [
            'type' => AbsenceText::typeName($absence->leaveType, $absence->type),
            'period' => AbsenceText::period($absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->partial_minutes),
        ]));

        return back();
    }

    public function decideCancellation(Request $request, Absence $absence, AbsenceService $service): RedirectResponse
    {
        $this->authorize('decideCancellation', $absence);
        $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [], (array) __('absences.attributes'));

        /** @var User $user */
        $user = $request->user();
        $accept = $request->string('decision')->toString() === 'accept';
        $absence = $service->decideCancellation($user, $absence, $accept, $request->string('comment')->toString());

        $this->toast(AbsenceText::get($accept ? 'leave.flash.cancellation_accepted' : 'leave.flash.cancellation_rejected', ['name' => $absence->user->name]));

        return back();
    }
}
