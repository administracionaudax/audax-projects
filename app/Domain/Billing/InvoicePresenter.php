<?php

namespace App\Domain\Billing;

use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedPayment;

/**
 * Facturas de Holded para la interfaz (Fase 12, D-385): contrato con resources/js/types/billing.ts
 * (HoldedInvoiceSummary y HoldedInvoiceDetail). Solo para quien tiene view-billing.
 */
final class InvoicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(HoldedInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'kind' => $invoice->kind->value,
            'issued_on' => $invoice->issued_on->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'contact_name' => $invoice->contact_name,
            'client' => $invoice->relationLoaded('client') && $invoice->client !== null ? ['id' => $invoice->client->id, 'name' => $invoice->client->name] : null,
            'currency' => $invoice->currency,
            'subtotal' => (string) $invoice->subtotal,
            'tax_total' => (string) $invoice->tax_total,
            'total' => (string) $invoice->total,
            'paid_total' => (string) $invoice->paid_total,
            'pending_total' => (string) $invoice->pending_total,
            'collection_status' => $invoice->collection_status->value,
            'is_draft' => $invoice->is_draft,
            'tags' => $invoice->tags ?? [],
            'links' => $invoice->relationLoaded('links') ? $invoice->links->map(fn (HoldedInvoiceLink $link): array => self::link($link))->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(HoldedInvoice $invoice): array
    {
        $invoice->loadMissing([
            'client:id,name',
            'lines',
            'payments',
            'links.project:id,code,name,billing_type,client_id',
            'links.hourBank:id,name,project_id',
            'links.creator:id,name',
            'rectified:id,number,kind,issued_on',
            'rectifications:id,number,kind,issued_on,subtotal,total,rectified_invoice_id',
        ]);

        return [
            ...self::summary($invoice),
            'holded_status' => $invoice->holded_status,
            'notes' => $invoice->notes,
            'synced_at' => $invoice->synced_at?->utc()->toIso8601ZuluString(),
            'pdf_stored' => $invoice->pdf_path !== null,
            'lines' => $invoice->lines->map(fn ($line): array => [
                'id' => $line->id,
                'name' => $line->name,
                'service_code' => $line->service_code,
                'kind' => InvoiceLinkSuggester::lineKind($line)->value,
                'description' => $line->description,
                'units' => (string) $line->units,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'subtotal' => (string) $line->subtotal,
                'tax_rate' => $line->tax_rate !== null ? (string) $line->tax_rate : null,
                'has_project' => $line->holded_project_id !== null,
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (HoldedPayment $payment): array => [
                'id' => $payment->id,
                'paid_on' => $payment->paid_on->toDateString(),
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
            ])->values()->all(),
            'rectified' => $invoice->rectified === null ? null : ['id' => $invoice->rectified->id, 'number' => $invoice->rectified->number, 'issued_on' => $invoice->rectified->issued_on->toDateString()],
            'rectifications' => $invoice->rectifications->map(fn (HoldedInvoice $credit): array => [
                'id' => $credit->id,
                'number' => $credit->number,
                'issued_on' => $credit->issued_on->toDateString(),
                'subtotal' => (string) $credit->subtotal,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(HoldedInvoiceLink $link): array
    {
        return [
            'id' => $link->id,
            'method' => $link->method->value,
            'project' => $link->relationLoaded('project')
                ? ['id' => $link->project->id, 'code' => $link->project->code, 'name' => $link->project->name]
                : null,
            'bank' => $link->relationLoaded('hourBank') && $link->hourBank !== null ? ['id' => $link->hourBank->id, 'name' => $link->hourBank->name] : null,
            'created_by' => $link->relationLoaded('creator') && $link->creator !== null ? $link->creator->name : null,
            // Para la línea de tiempo de la ficha (D-408): cuándo se enlazó a mano.
            'created_at' => $link->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
