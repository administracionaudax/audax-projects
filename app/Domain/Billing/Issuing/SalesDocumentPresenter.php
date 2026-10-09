<?php

namespace App\Domain\Billing\Issuing;

use App\Models\InvoiceRecord;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\SalesDocumentTax;

/**
 * Facturas propias para la interfaz (PLAN-EMISION §6; D-428): contrato con
 * `resources/js/types/invoicing.ts` (SalesDocumentDetail y SalesDocumentForm).
 */
final class SalesDocumentPresenter
{
    /**
     * La ficha: todo lo de la factura, sus líneas, el desglose, los enlaces, el registro y sus
     * rectificativas.
     *
     * @return array<string, mixed>
     */
    public static function detail(SalesDocument $document): array
    {
        $document->loadMissing([
            'client:id,name',
            'series:id,code,name,kind',
            'lines',
            'taxes',
            'links.project:id,code,name',
            'links.hourBank:id,name',
            'links.creator:id,name',
            'rectified:id,full_number,issue_date,status',
            'rectifiedHolded:id,number,issued_on',
            'rectifications:id,full_number,issue_date,total,subtotal,rectification_kind,status,rectified_document_id',
            'cancelledBy:id,full_number,issue_date',
            'issuer:id,name',
            'creator:id,name',
            'voider:id,name',
            'noProjectNeededBy:id,name',
            'paymentMethod:id,name',
            'records',
        ]);

        return [
            'id' => $document->id,
            'view_id' => -$document->id,
            'type' => $document->type->value,
            'status' => $document->status->value,
            'is_test' => $document->is_test,
            'number' => $document->full_number,
            'series' => $document->series === null ? null : ['id' => $document->series->id, 'code' => $document->series->code, 'name' => $document->series->name, 'test' => $document->series->isTest()],
            'issue_date' => $document->issue_date->toDateString(),
            'operation_date' => $document->operation_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'client' => ['id' => $document->client_id, 'name' => $document->client->name ?? $document->client_name],
            'client_name' => $document->client_name,
            'client_snapshot' => $document->client_snapshot,
            'language' => $document->language,
            'subtotal' => (string) $document->subtotal,
            'discount_total' => (string) $document->discount_total,
            'tax_total' => (string) $document->tax_total,
            'withholding_total' => (string) $document->withholding_total,
            'withholding_rate' => $document->withholding_rate === null ? null : (string) $document->withholding_rate,
            'total' => (string) $document->total,
            'paid_total' => (string) $document->paid_total,
            'body' => $document->body,
            'internal_note' => $document->internal_note,
            'customer_reference' => $document->customer_reference,
            'payment_method' => $document->paymentMethod?->name,
            'payment_text' => $document->payment_text,
            'rectification' => $document->isCreditNote() ? [
                'kind' => $document->rectification_kind?->value,
                'reason' => $document->rectification_reason,
                'code' => $document->rectification_code,
                'original' => $document->rectified !== null
                    ? ['id' => $document->rectified->id, 'view_id' => -$document->rectified->id, 'number' => $document->rectified->full_number, 'issue_date' => $document->rectified->issue_date->toDateString()]
                    : ($document->rectifiedHolded !== null ? ['id' => $document->rectifiedHolded->id, 'view_id' => $document->rectifiedHolded->id, 'number' => $document->rectifiedHolded->number, 'issue_date' => $document->rectifiedHolded->issued_on->toDateString()] : null),
            ] : null,
            'rectifications' => $document->rectifications->map(fn (SalesDocument $credit): array => [
                'id' => $credit->id,
                'number' => $credit->full_number,
                'issue_date' => $credit->issue_date->toDateString(),
                'subtotal' => (string) $credit->subtotal,
                'total' => (string) $credit->total,
                'kind' => $credit->rectification_kind?->value,
                'status' => $credit->status->value,
            ])->values()->all(),
            'cancelled_by' => $document->cancelledBy === null ? null : ['id' => $document->cancelledBy->id, 'number' => $document->cancelledBy->full_number, 'issue_date' => $document->cancelledBy->issue_date->toDateString()],
            // «No necesita proyecto» (D-431): quién, cuándo y por qué.
            'no_project' => $document->no_project_needed_at === null ? null : [
                'at' => $document->no_project_needed_at->utc()->toIso8601ZuluString(),
                'by' => $document->noProjectNeededBy?->name,
                'note' => $document->no_project_note,
            ],
            'voided' => $document->voided_at === null ? null : ['at' => $document->voided_at->utc()->toIso8601ZuluString(), 'by' => $document->voider?->name, 'reason' => $document->void_reason],
            'issued_at' => $document->issued_at?->utc()->toIso8601ZuluString(),
            'issued_by' => $document->issuer?->name,
            'created_at' => $document->created_at?->utc()->toIso8601ZuluString(),
            'created_by' => $document->creator?->name,
            'pdf_ready' => $document->pdf_sha256 !== null,
            'pdf_sha256' => $document->pdf_sha256,
            'lines' => $document->lines->map(fn (SalesDocumentLine $line): array => [
                'id' => $line->id,
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'name' => $line->name,
                'service_code' => $line->service_code,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit->value,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax_rate_id' => $line->tax_rate_id,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                'operation_type' => $line->operation_type?->value,
                'line_base' => (string) $line->line_base,
            ])->values()->all(),
            // El desglose congelado de una emitida o, en un borrador, el calculado desde sus líneas.
            'taxes' => $document->isDraft() ? self::draftTaxes($document) : $document->taxes->map(fn (SalesDocumentTax $tax): array => [
                'operation_type' => $tax->operation_type->value,
                'rate' => (string) $tax->rate,
                'base' => (string) $tax->base,
                'tax' => (string) $tax->tax,
                'legal_mention' => $tax->legal_mention,
            ])->values()->all(),
            'links' => $document->links->map(fn (SalesDocumentLink $link): array => [
                'id' => $link->id,
                'method' => $link->method,
                'project' => ['id' => $link->project->id, 'code' => $link->project->code, 'name' => $link->project->name],
                'bank' => $link->hourBank === null ? null : ['id' => $link->hourBank->id, 'name' => $link->hourBank->name],
                'created_by' => $link->creator?->name,
            ])->values()->all(),
            'records' => $document->records->map(fn (InvoiceRecord $record): array => [
                'id' => $record->id,
                'kind' => $record->kind->value,
                'seq' => $record->seq,
                'installation' => $record->sif_installation_id,
                'invoice_type' => $record->invoice_type,
                'hash' => $record->hash,
                'previous_hash' => $record->previous_hash,
                'is_first' => $record->is_first,
                'generated_at' => $record->generated_at_text,
            ])->values()->all(),
        ];
    }

    /**
     * Lo que necesita el editor para un borrador.
     *
     * @return array<string, mixed>
     */
    public static function form(SalesDocument $document): array
    {
        $document->loadMissing(['lines', 'links']);
        $link = $document->links->first(fn (SalesDocumentLink $link): bool => $link->method === 'manual');

        return [
            'id' => $document->id,
            'client_id' => $document->client_id,
            'series_id' => $document->series_id,
            'issue_date' => $document->issue_date->toDateString(),
            'operation_date' => $document->operation_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'payment_method_id' => $document->payment_method_id,
            'withholding_rate_id' => $document->withholding_rate_id,
            'body' => $document->body,
            'internal_note' => $document->internal_note,
            'customer_reference' => $document->customer_reference,
            'project_id' => $link?->project_id,
            'hour_bank_id' => $link?->hour_bank_id,
            'lines' => $document->lines->map(fn (SalesDocumentLine $line): array => [
                'id' => $line->id,
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'description' => $line->description ?? '',
                'quantity' => self::plain((string) $line->quantity),
                'unit' => $line->unit->value,
                'unit_price' => self::plain((string) $line->unit_price),
                'discount_pct' => self::plain((string) $line->discount_pct),
                'tax_rate_id' => $line->tax_rate_id,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{operation_type: string, rate: string, base: string, tax: string, legal_mention: null}>
     */
    private static function draftTaxes(SalesDocument $document): array
    {
        $items = $document->lines->filter(fn (SalesDocumentLine $line): bool => $line->isItem())->values();

        return array_map(fn (array $tax): array => [
            'operation_type' => $tax['operation_type'],
            'rate' => $tax['rate'],
            'base' => $tax['base'],
            'tax' => $tax['tax'],
            'legal_mention' => null,
        ], DocumentTotals::compute(DraftWriter::totalsInput($items))['taxes']);
    }

    /** «8.0000» → «8»; «60.5000» → «60.5» (para los campos del editor). */
    public static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
