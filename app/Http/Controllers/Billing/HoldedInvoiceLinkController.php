<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\HoldedInvoiceLinker;
use App\Domain\Billing\ReviewUndo;
use App\Http\Controllers\Controller;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Enlace a mano de una factura de Holded con un proyecto y, si es de bolsas, una de sus bolsas (Fase
 * 12, D-388). Quitar solo quita los manuales: los automáticos volverían en la sincronización.
 */
class HoldedInvoiceLinkController extends Controller
{
    public function store(Request $request, HoldedInvoice $invoice, HoldedInvoiceLinker $linker, ReviewUndo $undo): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'hour_bank_id' => ['nullable', 'integer', 'exists:hour_banks,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $project = Project::query()->findOrFail((int) $data['project_id']);
        $bank = isset($data['hour_bank_id']) ? HourBank::query()->findOrFail((int) $data['hour_bank_id']) : null;

        $link = $linker->link($invoice, $project, $bank, $user);
        $message = __('billing.invoices.linked', ['project' => $project->code]);
        // «Deshacer» en la bandeja «Por revisar» (D-414): solo el enlace que se acaba de crear.
        if ($link->wasRecentlyCreated) {
            $undo->remember($message, links: [$link->id]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(HoldedInvoice $invoice, HoldedInvoiceLink $link, HoldedInvoiceLinker $linker): RedirectResponse
    {
        abort_unless($link->holded_invoice_id === $invoice->id, 404);

        $linker->unlink($link);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.invoices.unlinked')]);

        return back();
    }
}
