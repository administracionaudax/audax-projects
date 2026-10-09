<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Reports\Pdf\PdfEngine;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Enums\RectificationKind;
use App\Enums\SalesDocumentStatus;
use App\Enums\TaxOperationType;
use App\Models\HoldedInvoice;
use App\Models\PaymentMethod;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El PDF de una factura propia (PLAN-EMISION §5.1, «InvoicePdf»; L-01 a L-10 y L-17; G-4, D-426):
 * HTML con la hoja de documentos de Audax (DM Sans incrustada, ReportHtml::theme) → Gotenberg (o el
 * HTML tal cual con REPORTS_PDF_DRIVER=html) → disco privado con su SHA-256.
 *
 * - Lleva todo lo del art. 6 del RD 1619/2012: número y serie, fecha de expedición (y de la
 *   operación si es otra), emisor y destinatario con NIF y domicilio, descripción, unidades, precio
 *   sin impuesto y descuento, base, tipo y cuota por tipo, la mención de cada exención o inversión
 *   del sujeto pasivo, la referencia a la factura rectificada con su motivo, y el Registro Mercantil
 *   del emisor (art. 24 del Código de Comercio). En español o en inglés, según el cliente.
 * - Una emitida se genera una vez desde sus copias congeladas y se archiva: el fichero ya no cambia
 *   (*trigger*) y su SHA-256 queda en la factura (T-PDF).
 * - Un borrador se genera al vuelo con la marca «Borrador» y sin número; no se guarda.
 * - Deja libre arriba, centrado, el hueco del QR de VeriFactu (E7, V-12), sin QR ni leyenda.
 */
final class InvoicePdf
{
    public function __construct(
        private readonly PdfEngine $engine,
        private readonly ReportHtml $html,
    ) {}

    public function render(SalesDocument $document): string
    {
        return $this->engine->render($this->html($document));
    }

    public function extension(): string
    {
        return $this->engine->extension();
    }

    public function mime(): string
    {
        return $this->engine->mime();
    }

    /**
     * Genera y archiva el PDF de una emitida (si aún no está). Devuelve su contenido.
     */
    public function archive(SalesDocument $document): string
    {
        $disk = Storage::disk((string) config('invoicing.disk'));

        if ($document->pdf_path !== null && $disk->exists($document->pdf_path)) {
            return (string) $disk->get($document->pdf_path);
        }

        if ($document->isDraft()) {
            throw new RuntimeException('Un borrador no tiene PDF archivado.');
        }

        $content = $this->render($document);
        $path = 'invoicing/'.$document->issue_date->year.'/'.$document->uuid.'.'.$this->extension();
        $disk->put($path, $content);

        // Solo la primera vez: el PDF archivado no se cambia (InvoicingGuards).
        SalesDocument::query()->whereKey($document->id)->whereNull('pdf_sha256')->update([
            'pdf_path' => $path,
            'pdf_sha256' => hash('sha256', $content),
            'pdf_generated_at' => CarbonImmutable::now(),
        ]);
        $document->refresh();

        return $document->pdf_path === $path ? $content : (string) $disk->get((string) $document->pdf_path);
    }

    public function html(SalesDocument $document): string
    {
        $language = $document->language === 'en' ? 'en' : 'es';
        $text = fn (string $key, array $replace = []): string => (string) __("invoicing.pdf.{$language}.{$key}", $replace);
        $draft = $document->isDraft();
        $document->loadMissing(['lines', 'paymentMethod', 'lines.taxRate']);

        $issuer = $draft || $document->issuer_snapshot === null ? FiscalParties::issuer() : $document->issuer_snapshot;
        $client = $draft || $document->client_snapshot === null ? FiscalParties::client($document->client()->firstOrFail()) : $document->client_snapshot;

        // Desglose: el congelado de una emitida o el calculado al vuelo de un borrador.
        if ($draft) {
            $items = $document->lines->filter(fn (SalesDocumentLine $line): bool => $line->isItem())->values();
            $totals = DocumentTotals::compute(DraftWriter::totalsInput($items), $document->withholding_rate === null ? null : (string) $document->withholding_rate);
            $mentions = $items->mapWithKeys(fn (SalesDocumentLine $line): array => [(string) $line->tax_rate_id => $line->taxRate?->mention($language)])->all();
            $taxes = array_map(fn (array $tax): array => [
                'operation_type' => $tax['operation_type'], 'rate' => $tax['rate'], 'base' => $tax['base'], 'tax' => $tax['tax'], 'mention' => $mentions[$tax['key']] ?? null,
            ], $totals['taxes']);
            $sums = ['subtotal' => $totals['subtotal'], 'discount' => $totals['discount_total'], 'tax' => $totals['tax_total'], 'withholding' => $totals['withholding_total'], 'total' => $totals['total']];
            $paymentText = $document->paymentMethod?->text($language, $issuer['iban'] ?? null);
        } else {
            $taxes = $document->taxes()->get()->map(fn (SalesDocumentTax $tax): array => [
                'operation_type' => $tax->operation_type->value, 'rate' => (string) $tax->rate, 'base' => (string) $tax->base, 'tax' => (string) $tax->tax, 'mention' => $tax->legal_mention,
            ])->all();
            $sums = ['subtotal' => (string) $document->subtotal, 'discount' => (string) $document->discount_total, 'tax' => (string) $document->tax_total, 'withholding' => (string) $document->withholding_total, 'total' => (string) $document->total];
            $paymentText = $document->payment_text;
        }

        $lines = $document->lines->map(fn (SalesDocumentLine $line): array => [
            'kind' => $line->kind,
            'name' => $line->name,
            'code' => $line->service_code,
            'description' => $line->description,
            'quantity' => self::number((string) $line->quantity, $language),
            'unit' => $line->isItem() ? $text('units.'.$line->unit->value) : '',
            'price' => self::money((string) $line->unit_price, $language, 4),
            'discount' => bccomp(DocumentTotals::num((string) $line->discount_pct), '0', 2) === 0 ? '' : self::number(DocumentTotals::num((string) $line->discount_pct), $language).' %',
            'tax' => $line->tax_rate === null && $line->taxRate === null ? '' : self::taxLabel(($line->operation_type ?? $line->taxRate->operation_type ?? TaxOperationType::S1)->value, (string) ($line->tax_rate ?? $line->taxRate->rate ?? '0'), $language),
            'base' => self::money($draft ? self::lineBase($line) : (string) $line->line_base, $language),
        ])->values()->all();

        $rectified = null;
        if ($document->isCreditNote()) {
            $original = $document->rectified_document_id !== null
                ? SalesDocument::query()->find($document->rectified_document_id, ['full_number', 'issue_date'])
                : null;
            $holded = $original === null && $document->rectified_holded_invoice_id !== null ? HoldedInvoice::query()->find($document->rectified_holded_invoice_id, ['number', 'issued_on']) : null;
            $rectified = [
                'number' => $original->full_number ?? $holded->number ?? '—',
                'date' => self::date($original->issue_date ?? $holded?->issued_on, $language),
                'reason' => (string) $document->rectification_reason,
                'kind' => $text('rectification.'.($document->rectification_kind ?? RectificationKind::Differences)->value),
            ];
        }

        $settings = InvoiceDocumentSettings::get();
        $logo = InvoiceDocumentSettings::logoHtml((string) ($issuer['legal_name'] ?? '')) ?? $this->html->logo();
        $number = $draft ? $text('draft_number') : (string) $document->full_number;

        return view('invoicing.pdf.invoice', [
            'lang' => $language,
            't' => $text,
            'theme' => ReportHtml::theme(),
            'logo' => $logo,
            'title' => ($document->isCreditNote() ? $text('credit_note') : $text('invoice')).' '.$number,
            'heading' => $document->isCreditNote() ? $text('credit_note') : $text('invoice'),
            'number' => $number,
            'draft' => $draft,
            'cancelled' => in_array($document->status, [SalesDocumentStatus::Cancelled, SalesDocumentStatus::Voided], true),
            'test' => $document->is_test,
            'issuer' => $issuer,
            'client' => $client,
            'country' => fn (?string $code): string => $code === null || $code === 'ES' ? '' : $code,
            'dates' => array_values(array_filter([
                [$text('issue_date'), self::date($document->issue_date, $language)],
                $document->operation_date !== null && ! $document->operation_date->equalTo($document->issue_date) ? [$text('operation_date'), self::date($document->operation_date, $language)] : null,
                $document->due_date !== null ? [$text('due_date'), self::date($document->due_date, $language)] : null,
                $document->customer_reference !== null ? [$text('reference'), $document->customer_reference] : null,
            ])),
            'lines' => $lines,
            'taxes' => array_map(fn (array $tax): array => [
                'label' => self::taxLabel($tax['operation_type'], $tax['rate'], $language),
                'base' => self::money($tax['base'], $language),
                'tax' => self::money($tax['tax'], $language),
                'mention' => $tax['mention'],
            ], $taxes),
            'mentions' => array_values(array_unique(array_filter(array_column($taxes, 'mention')))),
            'sums' => [
                'subtotal' => self::money($sums['subtotal'], $language),
                'discount' => bccomp(DocumentTotals::num($sums['discount']), '0', 2) === 0 ? null : self::money($sums['discount'], $language),
                'tax' => self::money($sums['tax'], $language),
                'withholding' => bccomp(DocumentTotals::num($sums['withholding']), '0', 2) === 0 ? null : '−'.self::money($sums['withholding'], $language),
                'withholding_rate' => $document->withholding_rate === null ? null : self::number((string) $document->withholding_rate, $language),
                'total' => self::money($sums['total'], $language),
            ],
            'payment' => $paymentText,
            'iban' => $issuer['iban'] ?? null ? PaymentMethod::formatIban((string) $issuer['iban']) : null,
            'rectified' => $rectified,
            'body' => $document->body,
            'footer' => $settings['footer'],
            'legal_text' => $settings['legal_text'],
        ])->render();
    }

    /** Base de una línea de un borrador (la guardada al guardar; si no, calculada). */
    private static function lineBase(SalesDocumentLine $line): string
    {
        return DocumentTotals::compute([[
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_pct' => (string) $line->discount_pct,
            'tax' => null,
        ]])['lines'][0]['base'];
    }

    public static function taxLabel(string $operationType, string $rate, string $language): string
    {
        return match ($operationType) {
            'S1' => (string) __("invoicing.pdf.{$language}.vat_rate", ['rate' => self::number($rate, $language)]),
            'S2' => (string) __("invoicing.pdf.{$language}.reverse_charge"),
            'N1', 'N2' => (string) __("invoicing.pdf.{$language}.not_subject"),
            default => (string) __("invoicing.pdf.{$language}.exempt"),
        };
    }

    /** «1.234,50 €» en español; «€1,234.50» en inglés. Con más decimales solo si los tiene (precios). */
    public static function money(string $amount, string $language, int $maxDecimals = 2): string
    {
        $decimals = 2;
        if ($maxDecimals > 2) {
            $trimmed = rtrim(rtrim(bcadd(DocumentTotals::num($amount), '0', $maxDecimals), '0'), '.');
            $decimals = max(2, strlen(strstr($trimmed, '.') ?: '.') - 1);
        }
        $negative = bccomp(DocumentTotals::num($amount), '0', $maxDecimals) < 0;
        $value = self::format(ltrim(bcadd(DocumentTotals::num($amount), '0', $decimals), '-'), $decimals, $language);

        return ($negative ? '−' : '').($language === 'en' ? '€'.$value : $value.' €');
    }

    /** Número sin ceros de más: «1», «7,5», «0,25». */
    public static function number(string $value, string $language): string
    {
        $trimmed = rtrim(rtrim(bcadd(DocumentTotals::num($value), '0', 4), '0'), '.');
        $negative = str_starts_with($trimmed, '-');
        $decimals = strlen(strstr($trimmed, '.') ?: '.') - 1;

        return ($negative ? '−' : '').self::format(ltrim($trimmed, '-'), $decimals, $language);
    }

    private static function format(string $positive, int $decimals, string $language): string
    {
        [$integer, $fraction] = array_pad(explode('.', $positive, 2), 2, '');
        $grouped = strrev(implode($language === 'en' ? ',' : '.', str_split(strrev($integer), 3)));

        return $decimals > 0 ? $grouped.($language === 'en' ? '.' : ',').str_pad(substr($fraction, 0, $decimals), $decimals, '0') : $grouped;
    }

    public static function date(?CarbonImmutable $date, string $language): string
    {
        return $date === null ? '—' : $date->format($language === 'en' ? 'd/m/Y' : 'd/m/Y');
    }
}
