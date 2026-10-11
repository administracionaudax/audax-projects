<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\BillingPaymentMethod;
use App\Enums\SalesDocumentStatus;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los cobros de las facturas propias (PLAN-EMISION §3.3, E2; D-436):
 * - **Registrar**: importe con IVA (por defecto, lo pendiente), fecha, forma de pago, referencia del
 *   banco y nota. Cobros parciales sin límite, pero nunca más de lo pendiente. En una rectificativa
 *   se registra la **devolución** al cliente (se guarda en negativo), como mucho por su total.
 * - **Anular** un cobro: no se borra; queda anulado con motivo, quién y cuándo, en la historia y en
 *   la auditoría (activitylog).
 * - `sales_documents.paid_total` es la suma de los cobros sin anular, recalculada en la misma
 *   transacción con la factura bloqueada (dos cobros a la vez no se pasan de lo pendiente). De ahí
 *   sale el estado (pendiente, cobrada en parte, cobrada, vencida) en la vista `billing_documents`,
 *   así el listado, la ficha, el Resumen y Ventas cuentan igual que los cobros de Holded.
 */
final class PaymentRecorder
{
    /**
     * @param  array{paid_on: string, amount: string, method: string, bank_reference?: string|null, note?: string|null}  $data
     */
    public function record(SalesDocument $document, array $data, User $by): Payment
    {
        $payment = DB::transaction(function () use ($document, $data, $by): Payment {
            $locked = SalesDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== SalesDocumentStatus::Issued) {
                throw ValidationException::withMessages(['amount' => __('invoicing.payments.errors.not_issued')]);
            }

            $amount = DocumentTotals::round($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => __('invoicing.payments.errors.positive')]);
            }

            $remaining = self::remaining($locked);
            if (bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __(
                    bccomp($remaining, '0', 2) <= 0 ? 'invoicing.payments.errors.nothing_pending' : 'invoicing.payments.errors.too_much',
                    ['max' => InvoicePdf::money($remaining, 'es')],
                )]);
            }

            $paidOn = CarbonImmutable::parse($data['paid_on']);
            if ($paidOn->toDateString() > LocalTime::todayString()) {
                throw ValidationException::withMessages(['paid_on' => __('invoicing.payments.errors.future')]);
            }
            if ($paidOn->toDateString() < $locked->issue_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => __('invoicing.payments.errors.before_issue', ['date' => $locked->issue_date->format('d/m/Y')])]);
            }

            $payment = Payment::query()->create([
                'sales_document_id' => $locked->id,
                'paid_on' => $paidOn->toDateString(),
                // Una devolución (en una rectificativa) va en negativo, como su total.
                'amount' => $locked->isCreditNote() ? bcmul($amount, '-1', 2) : $amount,
                'method' => BillingPaymentMethod::from($data['method']),
                'bank_reference' => self::clean($data['bank_reference'] ?? null),
                'note' => self::clean($data['note'] ?? null),
                'created_by' => $by->id,
            ]);

            self::refresh($locked);

            return $payment;
        });

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($document)
            ->event('payment_recorded')
            ->withProperties(['payment' => $payment->id, 'amount' => (string) $payment->amount, 'paid_on' => $payment->paid_on->toDateString(), 'method' => $payment->method->value])
            ->log('invoicing.payment_recorded');

        return $payment;
    }

    public function void(Payment $payment, string $reason, User $by): Payment
    {
        DB::transaction(function () use ($payment, $reason, $by): void {
            $document = SalesDocument::query()->lockForUpdate()->findOrFail($payment->sales_document_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['reason' => __('invoicing.payments.errors.already_voided')]);
            }

            $locked->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $by->id, 'void_reason' => trim($reason)])->save();
            self::refresh($document);
        });

        $payment->refresh();

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($payment->document)
            ->event('payment_voided')
            ->withProperties(['payment' => $payment->id, 'amount' => (string) $payment->amount, 'paid_on' => $payment->paid_on->toDateString(), 'reason' => $payment->void_reason])
            ->log('invoicing.payment_voided');

        return $payment;
    }

    /**
     * Lo que aún se puede registrar: lo pendiente de una factura o lo que queda por devolver de una
     * rectificativa (en positivo). Nunca negativo.
     *
     * @return numeric-string
     */
    public static function remaining(SalesDocument $document): string
    {
        if ($document->status !== SalesDocumentStatus::Issued) {
            return '0.00';
        }

        $total = DocumentTotals::num((string) $document->total);
        $paid = DocumentTotals::num((string) $document->paid_total);
        $remaining = $document->isCreditNote()
            ? bcsub(bcmul($total, '-1', 2), bcmul($paid, '-1', 2), 2)
            : bcsub($total, $paid, 2);

        return bccomp($remaining, '0', 2) > 0 ? DocumentTotals::fixed($remaining) : '0.00';
    }

    /** `paid_total` = suma de los cobros sin anular (no es fiscal: el *trigger* lo deja cambiar). */
    public static function refresh(SalesDocument $document): void
    {
        $cents = (int) Payment::query()->where('sales_document_id', $document->id)->whereNull('voided_at')
            ->selectRaw('COALESCE(SUM(CAST(ROUND(amount * 100) AS BIGINT)), 0) as cents')
            ->value('cents');

        $paid = bcdiv((string) $cents, '100', 2);
        SalesDocument::query()->whereKey($document->id)->update(['paid_total' => $paid]);
        $document->forceFill(['paid_total' => $paid])->syncOriginalAttributes('paid_total');
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
