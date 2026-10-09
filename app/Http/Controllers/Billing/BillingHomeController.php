<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /facturacion (D-401 y D-405): la portada de la sección. Hasta que llegue el Resumen (I1), quien ve
 * los importes entra en Ventas; quien solo ve «Vendido frente a real» (responsables y gestores, en
 * horas), en ese informe. El resto, 403.
 */
class BillingHomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $gate = Gate::forUser($user);

        if ($gate->allows('view-billing')) {
            return redirect()->route('billing.sales');
        }

        abort_unless($gate->allows('view-sold-vs-actual'), 403);

        return redirect()->route('billing.sold-vs-actual');
    }
}
