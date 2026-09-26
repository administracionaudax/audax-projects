<?php

namespace App\Http\Controllers\HourBanks;

use App\Domain\HourBanks\HourBankClosure;
use App\Http\Controllers\Controller;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cerrar una bolsa (quien gestiona el proyecto) y reabrirla (solo admin, si no está renovada).
 * Al cerrar queda registrado el saldo no consumido (SPEC §8.9).
 */
class HourBankStatusController extends Controller
{
    use AuthorizesRequests;

    public function close(Request $request, Project $project, HourBank $hourBank, HourBankClosure $closure): RedirectResponse
    {
        $this->authorize('close', $hourBank);

        /** @var User $user */
        $user = $request->user();
        $closed = $closure->close($hourBank, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('hour_banks.flash.closed', [
            'remaining' => Duration::format((int) $closed->closed_remaining_minutes),
        ])]);

        return back();
    }

    public function reopen(Project $project, HourBank $hourBank, HourBankClosure $closure): RedirectResponse
    {
        $this->authorize('reopen', $hourBank);

        $closure->reopen($hourBank);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('hour_banks.flash.reopened')]);

        return back();
    }
}
