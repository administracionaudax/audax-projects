<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\InvoiceRecordKind;
use App\Enums\SalesDocumentStatus;
use App\Models\InvoiceRecord;
use App\Models\NumberingSeries;
use App\Models\SalesDocument;
use App\Models\SifInstallation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La cadena de registros de facturación de cada instalación (PLAN-EMISION §4.3 y §5.2; V-05, D-420):
 * el siguiente número de orden, el registro anterior y su huella, el primero con `is_first`, y la
 * comprobación de toda la cadena y de la correlatividad de las series (`app:billing-verify-chain`).
 *
 * Se escribe solo dentro de la transacción de la emisión o de la anulación, con la instalación
 * bloqueada (lock()): así numeración y cadena van en serie aunque dos personas emitan a la vez. El
 * *trigger* de la base de datos es la segunda línea de defensa.
 */
final class RecordChain
{
    /**
     * Bloquea la instalación hasta el final de la transacción: un UPDATE de su fila (en PostgreSQL
     * bloquea la fila; en SQLite toma el candado de escritura), así nadie más emite en ella a la vez.
     */
    public static function lock(SifInstallation $installation): void
    {
        DB::table('sif_installations')->where('id', $installation->id)->update(['locked_at' => CarbonImmutable::now()]);
    }

    /**
     * Añade un registro a la cadena de la instalación (con la instalación ya bloqueada).
     *
     * @param  array{issuer_tax_id: string, invoice_number: string, issue_date_text: string, invoice_type?: string, tax_total_text?: string, total_text?: string}  $fields
     * @param  array<string, mixed>  $payload
     */
    public static function append(SifInstallation $installation, InvoiceRecordKind $kind, SalesDocument $document, array $fields, array $payload, ?CarbonImmutable $now = null): InvoiceRecord
    {
        $last = InvoiceRecord::query()->where('sif_installation_id', $installation->id)->orderByDesc('seq')->first(['id', 'seq', 'hash', 'invoice_number', 'issue_date_text', 'issuer_tax_id']);
        $generated = RecordHasher::timestamp($now ?? CarbonImmutable::now());
        $trimmed = array_map(fn (string $value): string => trim($value), $fields);
        $base = [
            ...$trimmed,
            'previous_hash' => $last->hash ?? '',
            'generated_at_text' => $generated,
        ];

        $hash = RecordHasher::hash($kind === InvoiceRecordKind::Alta
            ? RecordHasher::altaInput([
                'issuer_tax_id' => $base['issuer_tax_id'],
                'invoice_number' => $base['invoice_number'],
                'issue_date_text' => $base['issue_date_text'],
                'invoice_type' => $base['invoice_type'] ?? '',
                'tax_total_text' => $base['tax_total_text'] ?? '',
                'total_text' => $base['total_text'] ?? '',
                'previous_hash' => $base['previous_hash'],
                'generated_at_text' => $generated,
            ])
            : RecordHasher::anulacionInput([
                'issuer_tax_id' => $base['issuer_tax_id'],
                'invoice_number' => $base['invoice_number'],
                'issue_date_text' => $base['issue_date_text'],
                'previous_hash' => $base['previous_hash'],
                'generated_at_text' => $generated,
            ]));

        $payload['encadenamiento'] = $last === null
            ? ['primer_registro' => 'S']
            : ['registro_anterior' => [
                'id_emisor_factura' => $last->issuer_tax_id,
                'num_serie_factura' => $last->invoice_number,
                'fecha_expedicion_factura' => $last->issue_date_text,
                'huella' => $last->hash,
            ]];
        $payload['tipo_huella'] = '01';
        $payload['huella'] = $hash;
        $payload['fecha_hora_huso_gen_registro'] = $generated;

        $record = new InvoiceRecord;
        $record->forceFill([
            'sif_installation_id' => $installation->id,
            'seq' => ($last->seq ?? 0) + 1,
            'kind' => $kind,
            'sales_document_id' => $document->id,
            'issuer_tax_id' => $base['issuer_tax_id'],
            'invoice_number' => $base['invoice_number'],
            'issue_date_text' => $base['issue_date_text'],
            'invoice_type' => $kind === InvoiceRecordKind::Alta ? ($base['invoice_type'] ?? null) : null,
            'tax_total_text' => $kind === InvoiceRecordKind::Alta ? ($base['tax_total_text'] ?? null) : null,
            'total_text' => $kind === InvoiceRecordKind::Alta ? ($base['total_text'] ?? null) : null,
            'previous_hash' => $base['previous_hash'],
            'generated_at_text' => $generated,
            'is_first' => $last === null,
            'previous_record_id' => $last?->id,
            'hash' => $hash,
            'payload' => $payload,
        ])->save();

        return $record;
    }

    /**
     * Comprueba todas las cadenas: números de orden sin huecos, cada registro enlazado con el
     * anterior, cada huella recalculada desde sus campos, un registro de alta por factura emitida
     * con sus datos, y la correlatividad de cada serie y año (sin huecos ni repetidos).
     *
     * @return array{ok: bool, records: int, documents: int, problems: list<string>}
     */
    public function verify(): array
    {
        $problems = [];
        $count = 0;
        $previous = [];

        // Por instalación y orden, sin cargar modelos (pueden ser miles).
        foreach (DB::table('invoice_records')->orderBy('sif_installation_id')->orderBy('seq')->lazy(500) as $row) {
            $count++;
            $installation = (int) $row->sif_installation_id;
            $seq = (int) $row->seq;
            $last = $previous[$installation] ?? null;

            if ($last === null) {
                if ($seq !== 1 || $row->previous_hash !== '' || ! (bool) $row->is_first) {
                    $problems[] = __('invoicing.chain.problems.first', ['installation' => $installation, 'seq' => $seq]);
                }
            } elseif ($seq !== $last['seq'] + 1) {
                $problems[] = __('invoicing.chain.problems.gap', ['installation' => $installation, 'seq' => $seq, 'previous' => $last['seq']]);
            } elseif ($row->previous_hash !== $last['hash'] || (int) $row->previous_record_id !== $last['id'] || (bool) $row->is_first) {
                $problems[] = __('invoicing.chain.problems.link', ['installation' => $installation, 'seq' => $seq]);
            }

            $input = $row->kind === InvoiceRecordKind::Alta->value
                ? RecordHasher::altaInput([
                    'issuer_tax_id' => (string) $row->issuer_tax_id,
                    'invoice_number' => (string) $row->invoice_number,
                    'issue_date_text' => (string) $row->issue_date_text,
                    'invoice_type' => (string) $row->invoice_type,
                    'tax_total_text' => (string) $row->tax_total_text,
                    'total_text' => (string) $row->total_text,
                    'previous_hash' => (string) $row->previous_hash,
                    'generated_at_text' => (string) $row->generated_at_text,
                ])
                : RecordHasher::anulacionInput([
                    'issuer_tax_id' => (string) $row->issuer_tax_id,
                    'invoice_number' => (string) $row->invoice_number,
                    'issue_date_text' => (string) $row->issue_date_text,
                    'previous_hash' => (string) $row->previous_hash,
                    'generated_at_text' => (string) $row->generated_at_text,
                ]);
            if (! hash_equals(RecordHasher::hash($input), (string) $row->hash)) {
                $problems[] = __('invoicing.chain.problems.hash', ['installation' => $installation, 'seq' => $seq, 'number' => $row->invoice_number]);
            }

            $previous[$installation] = ['seq' => $seq, 'hash' => (string) $row->hash, 'id' => (int) $row->id];
        }

        [$documents, $documentProblems] = $this->verifyDocuments();

        $problems = [...$problems, ...$documentProblems];

        return ['ok' => $problems === [], 'records' => $count, 'documents' => $documents, 'problems' => $problems];
    }

    /**
     * Cada factura emitida tiene su registro de alta con su número, su fecha y sus importes, y cada
     * serie y año van del 1 al último sin huecos ni repetidos, como dice su contador.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function verifyDocuments(): array
    {
        $problems = [];
        $rows = DB::table('sales_documents')
            ->leftJoin('invoice_records', 'invoice_records.id', '=', 'sales_documents.invoice_record_id')
            ->where('sales_documents.status', '!=', SalesDocumentStatus::Draft->value)
            ->orderBy('sales_documents.series_id')->orderBy('sales_documents.year')->orderBy('sales_documents.number')
            ->get([
                'sales_documents.id', 'sales_documents.series_id', 'sales_documents.year', 'sales_documents.number', 'sales_documents.full_number',
                'sales_documents.issue_date', 'sales_documents.tax_total', 'sales_documents.subtotal',
                'invoice_records.id as record_id', 'invoice_records.kind as record_kind', 'invoice_records.sales_document_id as record_document',
                'invoice_records.invoice_number as record_number', 'invoice_records.issue_date_text as record_date',
                'invoice_records.tax_total_text as record_tax', 'invoice_records.total_text as record_total',
            ]);

        $counters = DB::table('numbering_counters')->orderBy('series_id')->orderBy('year')->get(['series_id', 'year', 'first_number', 'last_number']);
        $firsts = [];
        foreach ($counters as $counter) {
            $firsts[$counter->series_id.'-'.$counter->year] = (int) $counter->first_number;
        }

        $expected = [];
        foreach ($rows as $row) {
            $number = (string) $row->full_number;
            if ($row->record_id === null || $row->record_kind !== InvoiceRecordKind::Alta->value || (int) $row->record_document !== (int) $row->id) {
                $problems[] = __('invoicing.chain.problems.no_record', ['number' => $number]);
            } elseif ($row->record_number !== $number
                || $row->record_date !== CarbonImmutable::parse((string) $row->issue_date)->format('d-m-Y')
                || $row->record_tax !== RecordHasher::amount((string) $row->tax_total)
                || $row->record_total !== RecordHasher::amount(bcadd(DocumentTotals::num((string) $row->subtotal), DocumentTotals::num((string) $row->tax_total), 2))) {
                $problems[] = __('invoicing.chain.problems.mismatch', ['number' => $number]);
            }

            $key = $row->series_id.'-'.$row->year;
            $next = isset($expected[$key]) ? $expected[$key] + 1 : ($firsts[$key] ?? 1);
            if ((int) $row->number !== $next) {
                $problems[] = __('invoicing.chain.problems.correlative', ['number' => $number, 'expected' => $next]);
            }
            $expected[$key] = (int) $row->number;
        }

        // Cada anulada por error tiene su registro de anulación (V-03).
        $voided = DB::table('sales_documents')->where('status', SalesDocumentStatus::Voided->value)
            ->whereNotExists(fn ($records) => $records->selectRaw('1')->from('invoice_records')
                ->whereColumn('invoice_records.sales_document_id', 'sales_documents.id')
                ->where('invoice_records.kind', InvoiceRecordKind::Anulacion->value))
            ->orderBy('id')->pluck('full_number');
        foreach ($voided as $number) {
            $problems[] = __('invoicing.chain.problems.no_void_record', ['number' => $number]);
        }

        // El contador de cada serie y año llega al último emitido (ni más, que dejaría un hueco, ni menos).
        $codes = NumberingSeries::query()->pluck('code', 'id');
        foreach ($counters as $counter) {
            $last = $expected[$counter->series_id.'-'.$counter->year] ?? (int) $counter->first_number - 1;
            if ((int) $counter->last_number !== $last) {
                $problems[] = __('invoicing.chain.problems.counter', ['series' => $codes[$counter->series_id] ?? '?', 'year' => $counter->year, 'counter' => $counter->last_number, 'last' => $last]);
            }
        }

        return [$rows->count(), $problems];
    }
}
