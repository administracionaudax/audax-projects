<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingSummary;
use App\Http\Controllers\Controller;
use App\Models\HourBank;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /facturacion (I1, D-411; antes D-401 y D-405): el Resumen para quien ve los importes
 * (view-billing): qué te deben, qué falta por facturar, qué falta por revisar y cómo va el periodo
 * (?periodo= del listado de facturas; sin él, el año en curso). Quien solo ve «Vendido frente a real»
 * (responsables y gestores, en horas) sigue entrando en ese informe. El resto, 403.
 */
class BillingHomeController extends Controller
{
    public function __invoke(Request $request, BillingSummary $summary): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $gate = Gate::forUser($user);

        if (! $gate->allows('view-billing')) {
            abort_unless($gate->allows('view-sold-vs-actual'), 403);

            return redirect()->route('billing.sold-vs-actual');
        }

        /** @var array<string, mixed> $query */
        $query = $request->query();
        $period = BillingSummary::period($query);

        return Inertia::render('billing/summary', [
            'summary' => $summary->summary($user, $period),
            'explicit_period' => isset($query['periodo']),
            'can' => ['viewBanks' => $user->can('viewAny', HourBank::class)],
        ]);
    }
}
