<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Domain\Billing\Issuing\PaymentRecorder;
use App\Enums\BillingPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cobros de una factura propia (PLAN-EMISION §3.3; E2, D-436): registrar uno (también parcial, o la
 * devolución en una rectificativa) y anularlo con motivo. Detrás de los módulos y de use-invoicing
 * (la ruta) y de la matriz DocumentActions (manage-billing).
 */
class SalesDocumentPaymentController extends Controller
{
    public function store(Request $request, SalesDocument $document, PaymentRecorder $recorder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'record_payment'), 403);

        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', Rule::enum(BillingPaymentMethod::class)],
            'bank_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], (array) __('invoicing.payments.attributes'));

        $payment = $recorder->record($document, [
            'paid_on' => (string) $data['paid_on'],
            'amount' => (string) $data['amount'],
            'method' => (string) $data['method'],
            'bank_reference' => isset($data['bank_reference']) ? (string) $data['bank_reference'] : null,
            'note' => isset($data['note']) ? (string) $data['note'] : null,
        ], $user);

        $document->refresh();
        $key = $document->isCreditNote() ? 'refund_recorded' : (PaymentRecorder::remaining($document) === '0.00' ? 'paid' : 'payment_recorded');
        Inertia::flash('toast', ['type' => 'success', 'message' => __("invoicing.flash.{$key}", [
            'amount' => InvoicePdf::money(ltrim((string) $payment->amount, '-'), 'es'),
            'number' => $document->full_number,
        ])]);

        return back();
    }

    public function void(Request $request, SalesDocument $document, Payment $payment, PaymentRecorder $recorder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($payment->sales_document_id === $document->id, 404);
        abort_unless(DocumentActions::allows($document, $user, 'void_payment'), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [], (array) __('invoicing.payments.attributes'));

        $recorder->void($payment, (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.payment_voided')]);

        return back();
    }
}
