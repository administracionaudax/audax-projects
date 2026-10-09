<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\SalesDocumentType;
use App\Enums\ServiceUnit;
use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Borradores de factura (PLAN-EMISION §6.2, «Nueva factura»; D-428): crear y guardar desde el
 * editor, y duplicar una factura (propia o de Holded) como borrador. Un borrador no tiene número ni
 * cuenta como facturado (D-395: es previsto); sus importes se guardan para el listado.
 *
 * @phpstan-type DraftLine array{id?: int|null, kind: string, service_id?: int|null, description?: string|null, quantity?: string|int|float|null, unit?: string|null, unit_price?: string|int|float|null, discount_pct?: string|int|float|null, tax_rate_id?: int|null}
 * @phpstan-type DraftData array{client_id: int, series_id?: int|null, issue_date?: string|null, operation_date?: string|null, due_date?: string|null, payment_method_id?: int|null, withholding_rate_id?: int|null, body?: string|null, internal_note?: string|null, customer_reference?: string|null, project_id?: int|null, hour_bank_id?: int|null, lines: list<DraftLine>}
 */
final class DraftWriter
{
    /**
     * @param  DraftData  $data
     *
     * @throws ValidationException
     */
    public function save(?SalesDocument $document, array $data, User $by): SalesDocument
    {
        if ($document !== null && ! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => __('invoicing.errors.not_draft')]);
        }

        return DB::transaction(function () use ($document, $data, $by): SalesDocument {
            $client = Client::query()->withTrashed()->findOrFail($data['client_id']);
            $issueDate = isset($data['issue_date']) ? CarbonImmutable::parse($data['issue_date']) : LocalTime::today();
            $series = isset($data['series_id'])
                ? NumberingSeries::query()->findOrFail($data['series_id'])
                : self::defaultSeries(SalesDocumentType::Invoice, $issueDate);
            $payment = isset($data['payment_method_id']) ? PaymentMethod::query()->find($data['payment_method_id']) : null;
            $withholding = isset($data['withholding_rate_id'])
                ? TaxRate::query()->where('kind', TaxRateKind::Withholding->value)->find($data['withholding_rate_id'])
                : null;

            $document ??= new SalesDocument(['uuid' => (string) Str::uuid(), 'type' => SalesDocumentType::Invoice, 'created_by' => $by->id]);
            $document->fill([
                'is_test' => $series?->isTest() ?? false,
                'series_id' => $series?->id,
                'issue_date' => $issueDate->toDateString(),
                'operation_date' => $data['operation_date'] ?? null,
                'due_date' => $data['due_date'] ?? ($payment?->due_days !== null ? $issueDate->addDays($payment->due_days)->toDateString() : null),
                'client_id' => $client->id,
                'client_name' => FiscalParties::client($client)['legal_name'],
                'language' => self::language($client),
                'payment_method_id' => $payment?->id,
                'withholding_rate_id' => $withholding?->id,
                'withholding_rate' => $withholding?->rate,
                'body' => self::text($data['body'] ?? null),
                'internal_note' => self::text($data['internal_note'] ?? null),
                'customer_reference' => self::text($data['customer_reference'] ?? null),
                'updated_by' => $by->id,
            ]);
            $document->save();

            $this->writeLines($document, $data['lines']);
            self::refreshTotals($document);
            $this->writeLink($document, $data['project_id'] ?? null, $data['hour_bank_id'] ?? null, $by);

            return $document;
        });
    }

    /**
     * Duplica una factura propia o de Holded como borrador nuevo (H-033; D-424): mismo cliente,
     * líneas y textos, con la fecha de hoy y su vencimiento; sin número.
     *
     * @throws ValidationException
     */
    public function duplicate(SalesDocument|HoldedInvoice $source, User $by): SalesDocument
    {
        if ($source instanceof HoldedInvoice) {
            return $this->duplicateHolded($source, $by);
        }

        if ($source->isCreditNote()) {
            throw ValidationException::withMessages(['document' => __('invoicing.errors.duplicate_credit_note')]);
        }

        $source->loadMissing(['lines', 'links']);
        $link = $source->links->first();
        $series = $source->series_id !== null ? NumberingSeries::query()->find($source->series_id) : null;

        $copy = $this->save(null, [
            'client_id' => $source->client_id,
            'series_id' => $series !== null && $series->starts_on !== null && $series->starts_on->toDateString() > LocalTime::todayString() ? null : $series?->id,
            'issue_date' => LocalTime::today()->toDateString(),
            'payment_method_id' => $source->payment_method_id,
            'withholding_rate_id' => $source->withholding_rate_id,
            'body' => $source->body,
            'internal_note' => $source->internal_note,
            'customer_reference' => $source->customer_reference,
            'project_id' => $link?->project_id,
            'hour_bank_id' => $link?->hour_bank_id,
            'lines' => array_values($source->lines->map(fn (SalesDocumentLine $line): array => [
                'kind' => $line->kind,
                'service_id' => $line->service_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit->value,
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax_rate_id' => $line->tax_rate_id,
            ])->all()),
        ], $by);

        $copy->forceFill(['source_document_id' => $source->id])->save();

        return $copy;
    }

    /** Una factura de Holded como borrador propio (el mes en paralelo, §7.3). */
    private function duplicateHolded(HoldedInvoice $source, User $by): SalesDocument
    {
        $clientId = $source->client_id;
        if ($clientId === null) {
            throw ValidationException::withMessages(['document' => __('invoicing.errors.duplicate_no_client')]);
        }

        $source->loadMissing(['lines', 'links']);
        $services = CatalogService::query()->whereNotNull('code')->get(['id', 'code'])->keyBy('code');
        $vat = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('operation_type', TaxOperationType::S1->value)->get(['id', 'rate']);
        $default = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('is_default', true)->first(['id'])?->id;
        $link = $source->links->first();
        $sign = $source->kind->value === 'credit_note' ? '-1' : '1';

        $copy = $this->save(null, [
            'client_id' => $clientId,
            'issue_date' => LocalTime::today()->toDateString(),
            'payment_method_id' => PaymentMethod::query()->active()->where('is_default', true)->first(['id'])?->id,
            'body' => $source->notes,
            'project_id' => $link?->project_id,
            'hour_bank_id' => $link?->hour_bank_id,
            'lines' => array_values($source->lines->map(fn (HoldedInvoiceLine $line): array => [
                'kind' => 'item',
                'service_id' => $line->service_code !== null ? $services->get($line->service_code)?->id : null,
                'description' => trim(implode("\n", array_filter([$line->service_code === null ? $line->name : null, $line->description]))) ?: $line->name,
                'quantity' => bcmul(DocumentTotals::num((string) $line->units), DocumentTotals::num($sign), 4),
                'unit' => 'unit',
                'unit_price' => (string) $line->unit_price,
                'discount_pct' => (string) $line->discount_pct,
                'tax_rate_id' => $line->tax_rate === null ? $default : ($vat->first(fn (TaxRate $rate): bool => bccomp(DocumentTotals::num((string) $rate->rate), DocumentTotals::num((string) $line->tax_rate), 2) === 0)->id ?? $default),
            ])->all()),
        ], $by);

        $copy->forceFill(['source_holded_invoice_id' => $source->id])->save();

        return $copy;
    }

    /** La serie por defecto de un tipo en una fecha: la normal si ya ha empezado; si no, la de pruebas (D-419). */
    public static function defaultSeries(SalesDocumentType $type, CarbonImmutable $date): ?NumberingSeries
    {
        $series = NumberingSeries::query()->active()->where('document_type', $type->value)->orderByDesc('is_default')->orderBy('id')->get();

        return $series->first(fn (NumberingSeries $one): bool => $one->is_default && ($one->starts_on === null || $one->starts_on->toDateString() <= $date->toDateString()))
            ?? $series->first(fn (NumberingSeries $one): bool => $one->isTest())
            ?? $series->first();
    }

    /** Recalcula y guarda los importes de un borrador desde sus líneas (DocumentTotals). */
    public static function refreshTotals(SalesDocument $document): void
    {
        $lines = $document->lines()->with('taxRate')->get();
        $items = $lines->filter(fn (SalesDocumentLine $line): bool => $line->isItem())->values();
        $totals = DocumentTotals::compute(self::totalsInput($items), $document->withholding_rate === null ? null : (string) $document->withholding_rate);

        foreach ($items as $index => $line) {
            $base = $totals['lines'][$index]['base'];
            if ((string) $line->line_base !== $base) {
                $line->forceFill(['line_base' => $base])->save();
            }
        }

        $document->forceFill([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'withholding_total' => $totals['withholding_total'],
            'total' => $totals['total'],
        ])->save();
    }

    /**
     * @param  Collection<int, SalesDocumentLine>  $items
     * @return list<array{quantity: string, unit_price: string, discount_pct: string, tax: array{key: string, rate: string, operation_type: string}|null}>
     */
    public static function totalsInput(Collection $items): array
    {
        return array_values($items->map(fn (SalesDocumentLine $line): array => [
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_pct' => (string) $line->discount_pct,
            // El tipo copiado en la línea al guardarla (una rectificativa lleva el de la original).
            'tax' => $line->tax_rate_id === null ? null : [
                'key' => (string) $line->tax_rate_id,
                'rate' => (string) ($line->tax_rate ?? $line->taxRate->rate ?? '0'),
                'operation_type' => ($line->operation_type ?? $line->taxRate->operation_type ?? TaxOperationType::S1)->value,
            ],
        ])->all());
    }

    /**
     * @param  list<DraftLine>  $lines
     */
    private function writeLines(SalesDocument $document, array $lines): void
    {
        $existing = $document->lines()->get()->keyBy('id');
        $services = CatalogService::query()->whereKey(array_values(array_filter(array_map(fn (array $line): ?int => $line['service_id'] ?? null, $lines))))->get()->keyBy('id');
        $rates = TaxRate::query()->whereKey(array_values(array_filter(array_map(fn (array $line): ?int => $line['tax_rate_id'] ?? null, $lines))))->get()->keyBy('id');
        $kept = [];

        foreach ($lines as $position => $data) {
            $service = isset($data['service_id']) ? $services->get($data['service_id']) : null;
            $rate = isset($data['tax_rate_id']) ? $rates->get($data['tax_rate_id']) : null;
            $item = $data['kind'] === 'item';
            $attributes = [
                'position' => $position + 1,
                'kind' => $item ? 'item' : 'text',
                'service_id' => $item ? $service?->id : null,
                'name' => $item ? $service?->name : null,
                'service_code' => $item ? $service?->code : null,
                'description' => self::text($data['description'] ?? null),
                'quantity' => $item ? self::decimal($data['quantity'] ?? '1', 4) : '0',
                'unit' => $item ? ($data['unit'] ?? null ?: ($service->unit ?? ServiceUnit::Unit)->value) : ServiceUnit::Unit->value,
                'unit_price' => $item ? self::decimal($data['unit_price'] ?? '0', 4) : '0',
                'discount_pct' => $item ? self::decimal($data['discount_pct'] ?? '0', 2) : '0',
                'tax_rate_id' => $item ? $rate?->id : null,
                'tax_rate' => $item ? $rate?->rate : null,
                'operation_type' => $item ? $rate?->operation_type : null,
            ];

            $line = isset($data['id']) ? $existing->get($data['id']) : null;
            if ($line !== null) {
                $line->forceFill($attributes)->save();
            } else {
                $line = new SalesDocumentLine(['sales_document_id' => $document->id]);
                $line->forceFill($attributes)->save();
            }
            $kept[] = $line->id;
        }

        $document->lines()->whereNotIn('id', $kept)->delete();
        $document->unsetRelation('lines');
    }

    private function writeLink(SalesDocument $document, ?int $projectId, ?int $bankId, User $by): void
    {
        $document->links()->where('method', 'manual')->when($projectId !== null, fn ($q) => $q->where(fn ($w) => $w->where('project_id', '!=', $projectId)
            ->orWhereRaw('COALESCE(hour_bank_id, 0) <> ?', [$bankId ?? 0])))->delete();

        if ($projectId !== null && ! $document->links()->where('project_id', $projectId)->whereRaw('COALESCE(hour_bank_id, 0) = ?', [$bankId ?? 0])->exists()) {
            SalesDocumentLink::query()->create(['sales_document_id' => $document->id, 'project_id' => $projectId, 'hour_bank_id' => $bankId, 'method' => 'manual', 'created_by' => $by->id]);
        }
    }

    private static function language(Client $client): string
    {
        return ($client->billingProfile->language ?? 'es') === 'en' ? 'en' : 'es';
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function decimal(string|int|float|null $value, int $scale): string
    {
        $string = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;

        return bcadd(DocumentTotals::num($string), '0', $scale);
    }
}
