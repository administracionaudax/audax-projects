<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Time\TimeLockService;
use App\Enums\InvoiceRecordKind;
use App\Enums\RectificationKind;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Jobs\GenerateInvoicePdf;
use App\Models\HoldedInvoice;
use App\Models\NumberingCounter;
use App\Models\NumberingSeries;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentTax;
use App\Models\SalesDocumentTimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emitir una factura o una rectificativa (PLAN-EMISION §5.1 y §6.2; L-01 a L-10, V-01; D-419 a
 * D-422). Todo o nada, en una transacción con la instalación bloqueada (RecordChain::lock), así dos
 * emisiones a la vez reciben números seguidos y la cadena no se bifurca:
 *
 * 1. Comprueba (problems()): borrador, al menos una línea con importe y con impuesto, serie de su
 *    tipo y ya empezada, fecha no futura y no anterior a la última emitida de la serie (correlación
 *    de número y fecha), datos fiscales completos del emisor y del cliente, y en una rectificativa,
 *    su factura emitida y viva y su motivo.
 * 2. Sube el contador de la serie y el año (solo sube) y forma el número (F270001).
 * 3. Congela las líneas (tipo y calificación del impuesto, base), el desglose por tipo, las copias del
 *    emisor y del cliente y el texto de la forma de pago.
 * 4. Añade el registro de alta a la cadena (huella de VeriFactu) y deja la factura emitida.
 * 5. Bloquea sus horas; si es una anulación (D-244), deja la original «Anulada» y desbloquea las suyas.
 * 6. Tras el commit, el PDF (GenerateInvoicePdf): mientras, la ficha dice «Preparando el PDF».
 */
final class InvoiceIssuer
{
    public function __construct(private readonly TimeLockService $locks) {}

    /**
     * Lo que se va a emitir, para el diálogo de confirmación: el número previsto, la fecha, la
     * última fecha de la serie y lo que impide emitir.
     *
     * @return array{series: string|null, number: string|null, issue_date: string, last_date: string|null, problems: list<string>}
     */
    public function preview(SalesDocument $document): array
    {
        $series = $document->series_id !== null ? NumberingSeries::query()->find($document->series_id) : null;
        $year = $document->issue_date->year;
        $counter = $series === null ? null : NumberingCounter::query()->where('series_id', $series->id)->where('year', $year)->first();
        $next = $counter === null ? 1 : max($counter->last_number + 1, $counter->first_number);

        return [
            'series' => $series?->code,
            'number' => $series?->formatNumber($year, $next),
            'issue_date' => $document->issue_date->toDateString(),
            'last_date' => $series === null ? null : self::lastDate($series, $year)?->toDateString(),
            'problems' => $this->problems($document, $series),
        ];
    }

    /**
     * Lo que impide emitir este borrador (vacío si se puede).
     *
     * @return list<string>
     */
    public function problems(SalesDocument $document, ?NumberingSeries $series = null): array
    {
        $problems = [];
        $series ??= $document->series_id !== null ? NumberingSeries::query()->find($document->series_id) : null;
        $today = LocalTime::today();
        $items = $document->lines()->where('kind', 'item')->get(['id', 'tax_rate_id', 'line_base']);

        if (! $document->isDraft()) {
            return [__('invoicing.errors.not_draft')];
        }
        if ($items->isEmpty()) {
            $problems[] = __('invoicing.errors.no_lines');
        } elseif ($items->contains(fn (SalesDocumentLine $line): bool => $line->tax_rate_id === null)) {
            $problems[] = __('invoicing.errors.line_without_tax');
        }
        if (bccomp(DocumentTotals::num((string) $document->subtotal), '0', 2) === 0) {
            $problems[] = __('invoicing.errors.zero_total');
        } elseif ($document->type === SalesDocumentType::Invoice && bccomp(DocumentTotals::num((string) $document->total), '0', 2) < 0) {
            $problems[] = __('invoicing.errors.negative_invoice');
        }

        if ($series === null || $series->archived_at !== null) {
            $problems[] = __('invoicing.errors.no_series');
        } else {
            if ($series->document_type !== $document->type) {
                $problems[] = __('invoicing.errors.series_type', ['series' => $series->code]);
            }
            if ($series->sif_installation_id === null) {
                $problems[] = __('invoicing.errors.series_installation', ['series' => $series->code]);
            }
            // Fechas como texto (AAAA-MM-DD): hoy es el día de Madrid y las fechas se guardan sin hora.
            if ($series->starts_on !== null && $document->issue_date->toDateString() < $series->starts_on->toDateString()) {
                $problems[] = __('invoicing.errors.series_not_started', ['series' => $series->code, 'date' => $series->starts_on->format('d/m/Y')]);
            }
            $last = self::lastDate($series, $document->issue_date->year);
            if ($last !== null && $document->issue_date->toDateString() < $last->toDateString()) {
                $problems[] = __('invoicing.errors.date_before_last', ['series' => $series->code, 'date' => $last->format('d/m/Y')]);
            }
        }

        if ($document->issue_date->toDateString() > $today->toDateString()) {
            $problems[] = __('invoicing.errors.future_date');
        }
        foreach (FiscalParties::issuerMissing() as $field) {
            $problems[] = __('invoicing.errors.issuer_missing', ['field' => __("invoicing.fields.{$field}")]);
        }
        $client = $document->client()->first();
        if ($client !== null) {
            foreach (FiscalParties::clientMissing($client) as $field) {
                $problems[] = __('invoicing.errors.client_missing', ['field' => __("invoicing.fields.{$field}")]);
            }
        }

        if ($document->isCreditNote()) {
            $problems = [...$problems, ...$this->rectifiedProblems($document)];
        }

        return $problems;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function issue(SalesDocument $draft, User $by): SalesDocument
    {
        if (! InvoicingAccess::manages($by)) {
            throw new AuthorizationException(__('invoicing.errors.cannot_issue'));
        }

        $series = $draft->series_id !== null ? NumberingSeries::query()->with('installation')->find($draft->series_id) : null;
        $installation = $series?->installation;

        if ($series === null || $installation === null) {
            throw ValidationException::withMessages(['series_id' => __('invoicing.errors.no_series')]);
        }

        $issued = DB::transaction(function () use ($draft, $by, $series, $installation): SalesDocument {
            RecordChain::lock($installation);

            /** @var SalesDocument $document */
            $document = SalesDocument::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $problems = $this->problems($document, $series);
            if ($problems !== []) {
                throw ValidationException::withMessages(['document' => $problems]);
            }

            $year = $document->issue_date->year;
            $number = self::nextNumber($series, $year);
            $fullNumber = $series->formatNumber($year, $number);

            // Líneas, desglose y copias congelados.
            $lines = $document->lines()->with('taxRate')->get();
            $items = $lines->filter(fn (SalesDocumentLine $line): bool => $line->isItem())->values();
            $totals = DocumentTotals::compute(DraftWriter::totalsInput($items), $document->withholding_rate === null ? null : (string) $document->withholding_rate);
            foreach ($items as $index => $line) {
                $line->forceFill([
                    'tax_rate' => $line->tax_rate ?? $line->taxRate?->rate,
                    'operation_type' => $line->operation_type ?? $line->taxRate?->operation_type,
                    'line_base' => $totals['lines'][$index]['base'],
                ])->save();
            }
            $mentions = $items->mapWithKeys(fn (SalesDocumentLine $line): array => [(string) $line->tax_rate_id => $line->taxRate?->mention($document->language)])->all();
            foreach ($totals['taxes'] as $group) {
                SalesDocumentTax::query()->create([
                    'sales_document_id' => $document->id,
                    'tax_rate_id' => (int) $group['key'],
                    'operation_type' => $group['operation_type'],
                    'rate' => $group['rate'],
                    'base' => $group['base'],
                    'tax' => $group['tax'],
                    'legal_mention' => $mentions[$group['key']] ?? null,
                ]);
            }

            $client = FiscalParties::client($document->client()->firstOrFail());
            $issuer = FiscalParties::issuer();
            $document->forceFill([
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'withholding_total' => $totals['withholding_total'],
                'total' => $totals['total'],
                'year' => $year,
                'number' => $number,
                'full_number' => $fullNumber,
            ]);
            $document->payment_text = $document->paymentMethod?->text($document->language, $issuer['iban'] ?? null);

            $rectified = $this->rectifiedReferences($document);
            $invoiceType = $document->type->recordType($document->rectification_code);
            $document->setRelation('lines', $lines);
            $record = RecordChain::append($installation, InvoiceRecordKind::Alta, $document, [
                'issuer_tax_id' => (string) ($issuer['tax_id'] ?? ''),
                'invoice_number' => $fullNumber,
                'issue_date_text' => RecordHasher::date($document->issue_date),
                'invoice_type' => $invoiceType,
                'tax_total_text' => RecordHasher::amount($totals['tax_total']),
                'total_text' => RecordHasher::amount($totals['gross_total']),
            ], RecordPayload::alta($document, $installation, $issuer, $client, $document->taxes()->get(), $rectified, $invoiceType));

            $document->forceFill([
                'status' => SalesDocumentStatus::Issued,
                'client_name' => $client['legal_name'],
                'client_snapshot' => $client,
                'issuer_snapshot' => $issuer,
                'issued_at' => CarbonImmutable::now(),
                'issued_by' => $by->id,
                'invoice_record_id' => $record->id,
            ])->save();

            // Las horas de sus líneas, bloqueadas con la factura como referencia (§3.8).
            $entries = SalesDocumentTimeEntry::query()->whereIn('sales_document_line_id', $items->modelKeys())->whereNull('released_at')->pluck('time_entry_id')->all();
            $this->locks->lockForDocument($by, $document, array_values(array_map('intval', $entries)));

            if ($document->rectification_kind === RectificationKind::Cancellation && $document->rectified_document_id !== null) {
                $this->cancelOriginal($document, $by);
            }

            return $document;
        });

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($issued)
            ->event('issued')
            ->withProperties(['number' => $issued->full_number, 'total' => (string) $issued->total, 'test' => $issued->is_test, 'record' => $issued->invoice_record_id])
            ->log('invoicing.issued');

        GenerateInvoicePdf::dispatch($issued->id)->afterCommit();

        return $issued->refresh();
    }

    /** El siguiente número de la serie en el año: el contador sube en una sola sentencia (§4.3). */
    private static function nextNumber(NumberingSeries $series, int $year): int
    {
        DB::table('numbering_counters')->insertOrIgnore([
            'series_id' => $series->id,
            'year' => $year,
            'first_number' => 1,
            'last_number' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $counter = DB::table('numbering_counters')->where('series_id', $series->id)->where('year', $year)->first(['first_number', 'last_number']);
        $next = max((int) $counter->last_number + 1, (int) $counter->first_number);

        DB::table('numbering_counters')->where('series_id', $series->id)->where('year', $year)
            ->update(['last_number' => $next, 'updated_at' => CarbonImmutable::now()]);

        return $next;
    }

    /** La fecha de la última factura emitida de la serie en el año (para no desordenarla). */
    public static function lastDate(NumberingSeries $series, int $year): ?CarbonImmutable
    {
        $last = SalesDocument::query()->where('series_id', $series->id)->where('year', $year)
            ->where('status', '!=', SalesDocumentStatus::Draft->value)
            ->orderByDesc('number')->value('issue_date');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function rectifiedProblems(SalesDocument $document): array
    {
        if ($document->rectification_reason === null || trim($document->rectification_reason) === '') {
            return [__('invoicing.errors.reason_required')];
        }

        if ($document->rectified_document_id !== null) {
            $original = SalesDocument::query()->find($document->rectified_document_id);
            if ($original === null || $original->status !== SalesDocumentStatus::Issued || $original->isCreditNote()) {
                return [__('invoicing.errors.rectified_not_alive')];
            }
            if ($original->is_test !== $document->is_test) {
                return [__('invoicing.errors.rectified_test')];
            }
            if ($document->issue_date->toDateString() < $original->issue_date->toDateString()) {
                return [__('invoicing.errors.rectified_before', ['date' => $original->issue_date->format('d/m/Y')])];
            }
        } elseif ($document->rectified_holded_invoice_id === null) {
            return [__('invoicing.errors.rectified_not_alive')];
        }

        return [];
    }

    /**
     * @return list<array{number: string, date: string}>
     */
    private function rectifiedReferences(SalesDocument $document): array
    {
        if ($document->rectified_document_id !== null) {
            $original = SalesDocument::query()->find($document->rectified_document_id, ['full_number', 'issue_date']);

            return $original === null ? [] : [['number' => (string) $original->full_number, 'date' => RecordHasher::date($original->issue_date)]];
        }

        if ($document->rectified_holded_invoice_id !== null) {
            $original = HoldedInvoice::query()->find($document->rectified_holded_invoice_id, ['number', 'issued_on']);

            return $original === null ? [] : [['number' => (string) $original->number, 'date' => RecordHasher::date($original->issued_on)]];
        }

        return [];
    }

    /** La anulación (D-244): la original queda «Anulada» por esta rectificativa y sus horas, libres. */
    private function cancelOriginal(SalesDocument $credit, User $by): void
    {
        /** @var SalesDocument $original */
        $original = SalesDocument::query()->whereKey($credit->rectified_document_id)->lockForUpdate()->firstOrFail();
        $original->forceFill(['status' => SalesDocumentStatus::Cancelled, 'cancelled_by_id' => $credit->id])->save();

        self::releaseHours($original, $by, $this->locks);
    }

    /** Libera las horas de una factura (anulación o anulación por error): vuelven a poder facturarse. */
    public static function releaseHours(SalesDocument $document, User $by, TimeLockService $locks): int
    {
        $lineIds = SalesDocumentLine::query()->where('sales_document_id', $document->id)->pluck('id');
        SalesDocumentTimeEntry::query()->whereIn('sales_document_line_id', $lineIds)->whereNull('released_at')->update(['released_at' => CarbonImmutable::now()]);

        return $locks->releaseForDocument($by, $document);
    }
}
