<?php

use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Domain\Billing\Issuing\RecordHasher;
use App\Enums\InvoiceRecordKind;
use App\Enums\SalesDocumentStatus;
use App\Models\ClientBillingProfile;
use App\Models\InvoiceRecord;
use App\Models\NumberingCounter;
use App\Models\SalesDocument;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Emitir (PLAN-EMISION §5.1 y §6.2; L-01 a L-10, V-01; D-419 a D-422): T-NUM, T-SNAP y T-TOT.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
});

it('emite con el número siguiente de la serie, las copias, el desglose, el registro y el PDF', function () {
    $draft = invoicingDraft($this->admin, $this->client, [invoicingLine('15', '60', 'iva_21', '5'), invoicingLine('2', '50', 'iva_10')]);

    expect($draft->status)->toBe(SalesDocumentStatus::Draft)
        ->and($draft->full_number)->toBeNull()
        ->and((string) $draft->subtotal)->toBe('955.00');

    $issued = app(InvoiceIssuer::class)->issue($draft, $this->admin);

    expect($issued->status)->toBe(SalesDocumentStatus::Issued)
        ->and($issued->full_number)->toBe('F270001')
        ->and($issued->year)->toBe(2027)
        ->and($issued->number)->toBe(1)
        ->and((string) $issued->subtotal)->toBe('955.00')
        ->and((string) $issued->tax_total)->toBe('189.55')
        ->and((string) $issued->total)->toBe('1144.55')
        ->and($issued->issuer_snapshot['tax_id'])->toBe('B12345678')
        ->and($issued->client_snapshot['legal_name'])->toBe($this->client->name.', S.L.')
        ->and($issued->payment_text)->toBe('Transferencia bancaria a la cuenta ES91 2100 0418 4502 0005 1332')
        ->and($issued->due_date?->toDateString())->toBe('2027-02-03')
        ->and($issued->taxes->map(fn ($tax) => [$tax->operation_type->value, (string) $tax->rate, (string) $tax->base, (string) $tax->tax])->all())
        ->toBe([['S1', '21.00', '855.00', '179.55'], ['S1', '10.00', '100.00', '10.00']]);

    $record = InvoiceRecord::query()->findOrFail($issued->invoice_record_id);
    expect($record->kind)->toBe(InvoiceRecordKind::Alta)
        ->and($record->seq)->toBe(1)
        ->and($record->is_first)->toBeTrue()
        ->and($record->previous_hash)->toBe('')
        ->and($record->invoice_number)->toBe('F270001')
        ->and($record->issue_date_text)->toBe('04-01-2027')
        ->and($record->invoice_type)->toBe('F1')
        ->and($record->tax_total_text)->toBe('189.55')
        ->and($record->total_text)->toBe('1144.55')
        ->and($record->hash)->toBe(RecordHasher::hash(RecordHasher::altaInput([
            'issuer_tax_id' => 'B12345678', 'invoice_number' => 'F270001', 'issue_date_text' => '04-01-2027', 'invoice_type' => 'F1',
            'tax_total_text' => '189.55', 'total_text' => '1144.55', 'previous_hash' => '', 'generated_at_text' => $record->generated_at_text,
        ])))
        ->and($record->generated_at_text)->toBe('2027-01-04T10:00:00+01:00')
        ->and($record->payload['desglose'][0])->toBe(['impuesto' => '01', 'clave_regimen' => '01', 'calificacion_operacion' => 'S1', 'tipo_impositivo' => '21.00', 'base_imponible' => '855.00', 'cuota_repercutida' => '179.55'])
        ->and($record->payload['destinatarios'][0])->toBe(['nombre_razon' => $this->client->name.', S.L.', 'nif' => 'B87654321'])
        ->and($record->payload['sistema_informatico']['id_sistema_informatico'])->toBe('AP')
        ->and($record->payload['encadenamiento'])->toBe(['primer_registro' => 'S']);

    // El PDF, archivado con su SHA-256 tras emitir (REPORTS_PDF_DRIVER=html en los tests).
    expect($issued->pdf_path)->not->toBeNull()
        ->and($issued->pdf_sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get((string) $issued->pdf_path)));
});

it('numera seguido en la serie y el año, sin huecos aunque se borre un borrador, y empieza de nuevo cada año', function () {
    $first = invoicingIssue($this->admin, $this->client);
    $deleted = invoicingDraft($this->admin, $this->client);
    $deleted->delete();
    $second = invoicingIssue($this->admin, $this->client);

    expect([$first->full_number, $second->full_number])->toBe(['F270001', 'F270002']);

    $this->travelTo(CarbonImmutable::parse('2028-01-02 09:00:00', 'Europe/Madrid'));
    $next = invoicingIssue($this->admin, $this->client);

    expect($next->full_number)->toBe('F280001')
        ->and(NumberingCounter::query()->where('series_id', invoicingSeries('F')->id)->orderBy('year')->pluck('last_number', 'year')->all())->toBe([2027 => 2, 2028 => 1]);
});

it('la serie de pruebas tiene su propio contador y su propia cadena', function () {
    $real = invoicingIssue($this->admin, $this->client);
    $test = invoicingIssue($this->admin, $this->client, null, ['series_id' => invoicingSeries('PRU')->id]);

    $realRecord = InvoiceRecord::query()->find($real->invoice_record_id);
    $testRecord = InvoiceRecord::query()->find($test->invoice_record_id);

    expect($test->full_number)->toBe('PRU270001')
        ->and($test->is_test)->toBeTrue()
        ->and($testRecord->sif_installation_id)->not->toBe($realRecord->sif_installation_id)
        ->and($testRecord->seq)->toBe(1)
        ->and($testRecord->is_first)->toBeTrue();
});

it('no emite con fecha anterior a la última de la serie, ni futura, ni antes de que empiece la serie', function () {
    invoicingIssue($this->admin, $this->client, null, ['issue_date' => '2027-01-04']);

    $earlier = invoicingDraft($this->admin, $this->client, null, ['issue_date' => '2027-01-03']);
    expect(fn () => app(InvoiceIssuer::class)->issue($earlier, $this->admin))
        ->toThrow(ValidationException::class, 'La última factura de la serie F es del 04/01/2027');
    expect(app(InvoiceIssuer::class)->preview($earlier))->toMatchArray(['number' => 'F270002', 'last_date' => '2027-01-04']);

    $future = invoicingDraft($this->admin, $this->client, null, ['issue_date' => '2027-01-05']);
    expect(fn () => app(InvoiceIssuer::class)->issue($future, $this->admin))->toThrow(ValidationException::class, 'no puede ser futura');

    $this->travelTo(CarbonImmutable::parse('2026-12-30 10:00:00', 'Europe/Madrid'));
    $before = invoicingDraft($this->admin, $this->client, null, ['issue_date' => '2026-12-30']);
    expect(fn () => app(InvoiceIssuer::class)->issue($before, $this->admin))->toThrow(ValidationException::class, 'La serie F empieza el 01/01/2027');

    // Ningún intento fallido consume número.
    expect(NumberingCounter::query()->where('series_id', invoicingSeries('F')->id)->where('year', 2027)->value('last_number'))->toBe(1);
});

it('no emite sin los datos fiscales del cliente o del emisor, ni sin líneas o sin impuesto', function () {
    $incomplete = invoicingClient(['address' => null, 'postal_code' => null], ['tax_id' => null]);
    $draft = invoicingDraft($this->admin, $incomplete);
    $problems = app(InvoiceIssuer::class)->problems($draft);

    expect($problems)->toContain('Faltan datos fiscales del cliente: el NIF.')
        ->toContain('Faltan datos fiscales del cliente: la dirección.')
        ->toContain('Faltan datos fiscales del cliente: el código postal.');

    Setting::set('billing_issuer', null);
    expect(app(InvoiceIssuer::class)->problems($draft))->toContain('Faltan datos del emisor en Ajustes: el NIF.');
    invoicingIssuer();

    $noTax = invoicingDraft($this->admin, $this->client, [['kind' => 'item', 'description' => 'Sin IVA', 'quantity' => '1', 'unit_price' => '10', 'tax_rate_id' => null]]);
    expect(app(InvoiceIssuer::class)->problems($noTax))->toContain('Cada línea con importe necesita su impuesto.');

    $zero = invoicingDraft($this->admin, $this->client, [invoicingLine('0', '10')]);
    expect(app(InvoiceIssuer::class)->problems($zero))->toContain('La factura no puede ser de 0 €.');

    // Un empresario de la UE necesita su NIF-IVA.
    $eu = invoicingClient(['country_code' => 'DE', 'tax_regime' => 'intra_eu'], ['tax_id' => 'DE-123']);
    expect(app(InvoiceIssuer::class)->problems(invoicingDraft($this->admin, $eu, [invoicingLine('1', '100', 'intra_eu')])))
        ->toContain('Faltan datos fiscales del cliente: el NIF-IVA de la UE.');
});

it('congela las copias: cambiar la ficha fiscal o el emisor no cambia la emitida ni su PDF (T-SNAP)', function () {
    $issued = invoicingIssue($this->admin, $this->client);
    $pdf = (string) Storage::disk('local')->get((string) $issued->pdf_path);

    ClientBillingProfile::query()->where('client_id', $this->client->id)->update(['legal_name' => 'Otro nombre, S.A.', 'address' => 'Otra calle, 2']);
    invoicingIssuer(['legal_name' => 'Audax Cambiada, S.L.']);

    $fresh = SalesDocument::query()->findOrFail($issued->id);
    expect($fresh->client_snapshot['legal_name'])->toBe($this->client->name.', S.L.')
        ->and($fresh->issuer_snapshot['legal_name'])->toBe('Audax Studio, S.L.');

    $again = $this->actingAs($this->admin)->get("/facturacion/documentos/{$issued->id}/pdf")->assertOk()->getContent();
    expect($again)->toBe($pdf)
        ->and(hash('sha256', (string) $again))->toBe($fresh->pdf_sha256)
        ->and($again)->toContain($this->client->name.', S.L.')->not->toContain('Otro nombre');
});

it('un borrador no se puede emitir dos veces ni cambia después', function () {
    $draft = invoicingDraft($this->admin, $this->client);
    app(InvoiceIssuer::class)->issue($draft, $this->admin);

    expect(fn () => app(InvoiceIssuer::class)->issue($draft, $this->admin))->toThrow(ValidationException::class)
        ->and(InvoiceRecord::query()->count())->toBe(1);
});
