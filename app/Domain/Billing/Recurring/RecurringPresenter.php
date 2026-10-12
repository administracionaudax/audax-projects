<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Issuing\SalesDocumentPresenter;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\RecurringInvoiceRun;
use App\Models\SalesDocument;
use Illuminate\Database\Eloquent\Collection;

/**
 * Las facturas recurrentes para la interfaz (E3): contrato con `resources/js/types/invoicing-work.ts`
 * (RecurringRow, RecurringDetail y RecurringForm).
 */
final class RecurringPresenter
{
    /** Las relaciones que necesita el listado (en una consulta cada una). */
    public const array LIST_RELATIONS = ['client:id,name', 'project:id,code,name', 'lines:id,recurring_invoice_id,kind,quantity,unit_price,discount_pct'];

    /**
     * @param  Collection<int, RecurringInvoice>  $recurrences
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $recurrences): array
    {
        $last = SalesDocument::query()->whereKey($recurrences->pluck('last_document_id')->filter()->values()->all())
            ->get(['id', 'full_number', 'status', 'issue_date'])->keyBy('id');

        return array_values($recurrences->map(fn (RecurringInvoice $recurring): array => [
            ...self::row($recurring),
            'last_document' => $recurring->last_document_id !== null && $last->has($recurring->last_document_id)
                ? self::documentRef($last->get($recurring->last_document_id))
                : null,
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(RecurringInvoice $recurring): array
    {
        return [
            'id' => $recurring->id,
            'url' => $recurring->url(),
            'name' => $recurring->name,
            'client' => ['id' => $recurring->client_id, 'name' => $recurring->client->name ?? '—'],
            'project' => $recurring->project === null ? null : ['id' => $recurring->project->id, 'code' => $recurring->project->code, 'name' => $recurring->project->name],
            'periodicity' => $recurring->periodicity->value,
            'interval_months' => $recurring->interval_months,
            'day_of_month' => $recurring->day_of_month,
            'next_run_on' => $recurring->next_run_on?->toDateString(),
            'subtotal' => RecurringInvoices::subtotal($recurring),
            'mode' => $recurring->mode,
            'auto_send' => $recurring->auto_send,
            'state' => $recurring->state(),
            'from_holded' => $recurring->holded_recurring_id !== null,
            'reviewed' => $recurring->reviewed_at !== null,
            'occurrences_count' => $recurring->occurrences_count,
            'max_occurrences' => $recurring->max_occurrences,
            'end_date' => $recurring->end_date?->toDateString(),
            'last_error' => $recurring->last_error,
        ];
    }

    /**
     * La ficha: lo del listado, las líneas, las próximas 12 fechas y el historial.
     *
     * @return array<string, mixed>
     */
    public static function detail(RecurringInvoice $recurring): array
    {
        $recurring->loadMissing([
            'client:id,name', 'project:id,code,name', 'hourBank:id,name', 'series:id,code,name', 'paymentMethod:id,name',
            'lines.service:id,code,name', 'lines.taxRate:id,name,rate', 'issuer:id,name', 'pauser:id,name', 'reviewer:id,name',
            'runs.document:id,full_number,status,issue_date,total,subtotal', 'runs.creator:id,name',
        ]);

        return [
            ...self::row($recurring),
            'bank' => $recurring->hourBank === null ? null : ['id' => $recurring->hourBank->id, 'name' => $recurring->hourBank->name],
            'start_date' => $recurring->start_date->toDateString(),
            'series' => $recurring->series === null ? null : ['id' => $recurring->series->id, 'code' => $recurring->series->code, 'name' => $recurring->series->name, 'test' => $recurring->series->isTest()],
            'payment_method' => $recurring->paymentMethod?->name,
            'due_days' => $recurring->due_days,
            'body' => $recurring->body,
            'internal_note' => $recurring->internal_note,
            'customer_reference' => $recurring->customer_reference,
            'issue_as' => $recurring->issuer?->name,
            'paused' => $recurring->paused_at === null ? null : ['at' => $recurring->paused_at->utc()->toIso8601ZuluString(), 'by' => $recurring->pauser?->name],
            'reviewed_by' => $recurring->reviewer === null ? null : ['at' => $recurring->reviewed_at?->utc()->toIso8601ZuluString(), 'by' => $recurring->reviewer->name],
            'last_error_at' => $recurring->last_error_at?->utc()->toIso8601ZuluString(),
            'lines' => array_values($recurring->lines->map(fn (RecurringInvoiceLine $line): array => [
                'id' => $line->id,
                'kind' => $line->kind,
                'service' => $line->service === null ? null : ['id' => $line->service->id, 'code' => $line->service->code, 'name' => $line->service->name],
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit->value,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax' => $line->taxRate?->name,
            ])->all()),
            'upcoming' => RecurringSchedule::upcoming($recurring, 12),
            'runs' => array_values($recurring->runs->map(fn (RecurringInvoiceRun $run): array => [
                'id' => $run->id,
                'period_on' => $run->period_on->toDateString(),
                'status' => $run->status,
                'trigger' => $run->trigger,
                'error' => $run->error,
                'created_by' => $run->creator?->name,
                'created_at' => $run->created_at?->utc()->toIso8601ZuluString(),
                'document' => $run->document === null ? null : [...self::documentRef($run->document), 'total' => (string) $run->document->total],
            ])->all()),
        ];
    }

    /**
     * Lo que necesita el editor (una recurrente guardada o los datos de partida).
     *
     * @param  array<string, mixed>|null  $defaults
     * @return array<string, mixed>
     */
    public static function form(?RecurringInvoice $recurring, ?array $defaults = null): array
    {
        if ($recurring === null) {
            $data = $defaults ?? [];
            $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

            return [
                'id' => null,
                'name' => $data['name'] ?? '',
                'client_id' => $data['client_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'hour_bank_id' => $data['hour_bank_id'] ?? null,
                'periodicity' => 'monthly',
                'interval_months' => 1,
                'day_of_month' => $data['day_of_month'] ?? 1,
                'start_date' => $data['start_date'] ?? null,
                'end' => 'never',
                'end_date' => null,
                'max_occurrences' => null,
                'mode' => 'draft',
                'auto_send' => false,
                'series_id' => $data['series_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'due_days' => null,
                'withholding_rate_id' => $data['withholding_rate_id'] ?? null,
                'body' => $data['body'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'lines' => array_values(array_map(fn (array $line): array => [
                    'id' => null,
                    'kind' => $line['kind'] ?? 'item',
                    'service_id' => $line['service_id'] ?? null,
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => SalesDocumentPresenter::plain((string) ($line['quantity'] ?? '1')),
                    'unit' => $line['unit'] ?? 'unit',
                    'unit_price' => SalesDocumentPresenter::plain((string) ($line['unit_price'] ?? '0')),
                    'discount_pct' => SalesDocumentPresenter::plain((string) ($line['discount_pct'] ?? '0')),
                    'tax_rate_id' => $line['tax_rate_id'] ?? null,
                ], $lines)),
            ];
        }

        $recurring->loadMissing('lines');

        return [
            'id' => $recurring->id,
            'name' => $recurring->name,
            'client_id' => $recurring->client_id,
            'project_id' => $recurring->project_id,
            'hour_bank_id' => $recurring->hour_bank_id,
            'periodicity' => $recurring->periodicity->value,
            'interval_months' => $recurring->interval_months,
            'day_of_month' => $recurring->day_of_month,
            'start_date' => $recurring->start_date->toDateString(),
            'end' => $recurring->end_date !== null ? 'date' : ($recurring->max_occurrences !== null ? 'count' : 'never'),
            'end_date' => $recurring->end_date?->toDateString(),
            'max_occurrences' => $recurring->max_occurrences,
            'mode' => $recurring->mode,
            'auto_send' => $recurring->auto_send,
            'series_id' => $recurring->series_id,
            'payment_method_id' => $recurring->payment_method_id,
            'due_days' => $recurring->due_days,
            'withholding_rate_id' => $recurring->withholding_rate_id,
            'body' => $recurring->body,
            'internal_note' => $recurring->internal_note,
            'customer_reference' => $recurring->customer_reference,
            'lines' => array_values($recurring->lines->map(fn (RecurringInvoiceLine $line): array => [
                'id' => $line->id,
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'description' => $line->description ?? '',
                'quantity' => SalesDocumentPresenter::plain((string) $line->quantity),
                'unit' => $line->unit->value,
                'unit_price' => SalesDocumentPresenter::plain((string) $line->unit_price),
                'discount_pct' => SalesDocumentPresenter::plain((string) $line->discount_pct),
                'tax_rate_id' => $line->tax_rate_id,
            ])->all()),
        ];
    }

    /**
     * @return array{id: int, view_id: int, number: string|null, status: string, issue_date: string, url: string}
     */
    private static function documentRef(SalesDocument $document): array
    {
        return [
            'id' => $document->id,
            'view_id' => -$document->id,
            'number' => $document->full_number,
            'status' => $document->status->value,
            'issue_date' => $document->issue_date->toDateString(),
            'url' => $document->url(),
        ];
    }
}
