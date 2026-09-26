<?php

namespace App\Http\Controllers\HourBanks;

use App\Domain\HourBanks\HourBankRenewal;
use App\Http\Controllers\Controller;
use App\Http\Requests\HourBanks\RenewHourBankRequest;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Renovar una bolsa (SPEC §8.7): bolsa nueva con los mismos parámetros (editables), la anterior
 * queda renovada, las tareas abiertas se mueven si se pide y las horas nunca.
 */
class HourBankRenewalController extends Controller
{
    public function __invoke(RenewHourBankRequest $request, Project $project, HourBank $hourBank, HourBankRenewal $renewal): RedirectResponse
    {
        $result = $renewal->renew($hourBank, $request->bankAttributes(), $request->boolean('move_open_tasks'));

        $message = $result['moved_tasks'] > 0
            ? trans_choice('hour_banks.flash.renewed_with_tasks', $result['moved_tasks'], ['count' => $result['moved_tasks']])
            : __('hour_banks.flash.renewed');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('projects.hour-banks.show', ['project' => $project, 'hourBank' => $result['bank']]);
    }
}
