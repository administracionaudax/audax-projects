<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingNav;
use App\Domain\Billing\ReviewUndo;
use App\Enums\CollectionStatus;
use App\Http\Controllers\Controller;
use App\Models\HoldedInvoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «No necesita proyecto» de una factura (D-431): gastos repercutidos o una factura suelta que no va a
 * ningún proyecto ni bolsa. Marcarla (con un motivo opcional) la saca de «Sin proyecto», del
 * contador de «Por revisar», de «Requiere atención» y de la cobertura; «Necesita proyecto» la
 * devuelve. Desde «Por revisar» (con su «Deshacer», D-414) y desde la ficha. Quién: view-billing (en
 * la ruta), como enlazar. Nunca se escribe en Holded.
 */
class NoProjectNeededController extends Controller
{
    public function store(Request $request, HoldedInvoice $invoice, ReviewUndo $undo): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:'.HoldedInvoice::NO_PROJECT_NOTE_MAX],
        ]);
        // Una anulada ya no cuenta en ningún sitio, y una enlazada ya tiene proyecto.
        abort_if($invoice->collection_status === CollectionStatus::Cancelled || $invoice->links()->exists(), 422, __('billing_rules.no_project.not_allowed'));

        /** @var User $user */
        $user = $request->user();
        $wasMarked = $invoice->noProjectNeeded();
        $invoice->markNoProjectNeeded($user, isset($data['note']) ? (string) $data['note'] : null);
        BillingNav::forget();

        $message = __('billing_rules.no_project.marked', ['invoice' => $invoice->number ?? __('billing_rules.no_project.draft')]);
        if (! $wasMarked) {
            $undo->remember($message, marks: [$invoice->id]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(HoldedInvoice $invoice): RedirectResponse
    {
        $invoice->clearNoProjectNeeded();
        BillingNav::forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing_rules.no_project.cleared', ['invoice' => $invoice->number ?? __('billing_rules.no_project.draft')])]);

        return back();
    }
}
