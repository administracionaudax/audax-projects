<?php

use App\Models\InvoiceRecord;
use App\Models\NumberingCounter;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentTax;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| T-INM (PLAN-EMISION §8.2; L-19, V-06; D-421): lo emitido no se cambia, tampoco a mano en la base
| de datos. En SQLite (Mac) y en PostgreSQL (servidor y CI) con sus *triggers*; el recálculo de la
| huella dentro de la base, solo en PostgreSQL.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    $this->issued = invoicingIssue($this->admin, $this->client);
});

it('no deja cambiar ningún campo fiscal de una emitida, pero sí la nota interna', function (string $column, mixed $value) {
    expect(inSavepoint(fn () => DB::table('sales_documents')->where('id', $this->issued->id)->update([$column => $value])))
        ->toThrow(QueryException::class);
})->with([
    'número' => ['full_number', 'F279999'],
    'fecha' => ['issue_date', '2027-01-01'],
    'cliente' => ['client_name', 'Otro'],
    'base' => ['subtotal', '1.00'],
    'total' => ['total', '1.00'],
    'copia del cliente' => ['client_snapshot', '{"name":"x"}'],
    'texto final' => ['body', 'Otro texto'],
    'vencimiento' => ['due_date', '2027-03-01'],
    'vuelve a borrador' => ['status', 'draft'],
    'el PDF archivado' => ['pdf_sha256', str_repeat('0', 64)],
]);

it('lo no fiscal sí se cambia en una emitida', function () {
    DB::table('sales_documents')->where('id', $this->issued->id)->update(['internal_note' => 'Revisar con el cliente', 'updated_at' => now()]);

    expect(SalesDocument::query()->find($this->issued->id)->internal_note)->toBe('Revisar con el cliente');
});

it('no deja borrar una emitida ni crear una ya emitida', function () {
    expect(inSavepoint(fn () => DB::table('sales_documents')->where('id', $this->issued->id)->delete()))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('sales_documents')->insert([
            'uuid' => 'x', 'type' => 'invoice', 'status' => 'issued', 'issue_date' => '2027-01-04', 'client_id' => $this->client->id, 'client_name' => 'x',
            'series_id' => invoicingSeries('F')->id, 'year' => 2027, 'number' => 99, 'full_number' => 'F270099', 'invoice_record_id' => 1,
            'issued_at' => now(), 'client_snapshot' => '{}', 'issuer_snapshot' => '{}',
        ])))->toThrow(QueryException::class);
});

it('no deja tocar las líneas ni el desglose de una emitida', function () {
    $line = SalesDocumentLine::query()->where('sales_document_id', $this->issued->id)->firstOrFail();
    $tax = SalesDocumentTax::query()->where('sales_document_id', $this->issued->id)->firstOrFail();

    expect(inSavepoint(fn () => DB::table('sales_document_lines')->where('id', $line->id)->update(['unit_price' => '1'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('sales_document_lines')->where('id', $line->id)->delete()))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('sales_document_lines')->insert(['sales_document_id' => $this->issued->id, 'position' => 9, 'kind' => 'item', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 1, 'discount_pct' => 0, 'line_base' => 1, 'origin' => 'manual'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('sales_document_taxes')->where('id', $tax->id)->update(['tax' => '0'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('sales_document_taxes')->where('id', $tax->id)->delete()))->toThrow(QueryException::class);

    // Las de un borrador sí.
    $draft = invoicingDraft($this->admin, $this->client);
    DB::table('sales_document_lines')->where('sales_document_id', $draft->id)->update(['unit_price' => '70']);
    expect(SalesDocumentLine::query()->where('sales_document_id', $draft->id)->value('unit_price'))->toBe('70.0000');
});

it('el registro de facturación es de solo alta', function () {
    $record = InvoiceRecord::query()->findOrFail($this->issued->invoice_record_id);

    expect(inSavepoint(fn () => DB::table('invoice_records')->where('id', $record->id)->update(['hash' => str_repeat('A', 64)])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('invoice_records')->where('id', $record->id)->delete()))->toThrow(QueryException::class);

    if (DB::getDriverName() === 'pgsql') {
        expect(inSavepoint(fn () => DB::statement('TRUNCATE invoice_records CASCADE')))->toThrow(QueryException::class)
            ->and(inSavepoint(fn () => DB::statement('TRUNCATE sales_documents CASCADE')))->toThrow(QueryException::class);
    }
});

it('no deja añadir a la cadena un registro que no sigue al último', function () {
    $record = InvoiceRecord::query()->findOrFail($this->issued->invoice_record_id);
    $row = fn (array $overrides): array => [
        'sif_installation_id' => $record->sif_installation_id, 'seq' => 2, 'kind' => 'alta', 'sales_document_id' => $this->issued->id,
        'issuer_tax_id' => 'B12345678', 'invoice_number' => 'F270002', 'issue_date_text' => '04-01-2027', 'invoice_type' => 'F1',
        'tax_total_text' => '0.00', 'total_text' => '0.00', 'previous_hash' => $record->hash, 'generated_at_text' => '2027-01-04T10:00:00+01:00',
        'is_first' => false, 'previous_record_id' => $record->id, 'hash' => str_repeat('B', 64), 'payload' => '{}', 'created_at' => now(), ...$overrides,
    ];

    expect(inSavepoint(fn () => DB::table('invoice_records')->insert($row(['seq' => 3]))))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('invoice_records')->insert($row(['previous_hash' => str_repeat('C', 64)]))))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('invoice_records')->insert($row(['is_first' => true]))))->toThrow(QueryException::class);

    // PostgreSQL además recalcula la huella: una inventada no entra.
    if (DB::getDriverName() === 'pgsql') {
        expect(inSavepoint(fn () => DB::table('invoice_records')->insert($row([]))))->toThrow(QueryException::class);
    }
});

it('los contadores solo suben y no se borran', function () {
    $counter = NumberingCounter::query()->where('series_id', invoicingSeries('F')->id)->where('year', 2027)->firstOrFail();

    expect(inSavepoint(fn () => DB::table('numbering_counters')->where('id', $counter->id)->update(['last_number' => 0])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('numbering_counters')->where('id', $counter->id)->delete()))->toThrow(QueryException::class);

    DB::table('numbering_counters')->where('id', $counter->id)->update(['last_number' => 5]);
    expect(NumberingCounter::query()->find($counter->id)->last_number)->toBe(5);
});

it('un borrador sí se borra (no tiene número) con sus líneas', function () {
    $draft = invoicingDraft($this->admin, $this->client);
    $draft->delete();

    expect(SalesDocumentLine::query()->where('sales_document_id', $draft->id)->exists())->toBeFalse();
});
