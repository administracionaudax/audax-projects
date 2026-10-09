<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\InvoiceDocumentSettings;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
| T-PDF (PLAN-EMISION §8.2; L-01 a L-10 y L-17; G-4, D-426): el HTML del PDF lleva cada mención del
| art. 6 del RD 1619/2012 (en español y en inglés) y el archivado no cambia. Con
| REPORTS_PDF_DRIVER=html el «PDF» es ese mismo HTML; en el servidor, Gotenberg lo convierte.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    InvoiceDocumentSettings::put([...InvoiceDocumentSettings::get(), 'footer' => 'Gracias por confiar en Audax.', 'legal_text' => 'Sus datos se tratan conforme al RGPD.']);
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
});

it('lleva todo el contenido obligatorio de una factura completa', function () {
    $invoice = invoicingIssue($this->admin, $this->client, [invoicingLine('15', '60', 'iva_21', '5'), invoicingLine('1', '200', 'exempt', '0', 'Formación')], [
        'operation_date' => '2026-12-31',
        'customer_reference' => 'PED-4529',
        'body' => 'Proyecto de diciembre.',
    ]);
    $html = (string) Storage::disk('local')->get((string) $invoice->pdf_path);

    expect($html)
        // L-01 número y serie; L-04 fecha de expedición; L-08 fecha de la operación.
        ->toContain('F270001')->toContain('Fecha de expedición')->toContain('04/01/2027')->toContain('Fecha de la operación')->toContain('31/12/2026')
        // L-05 emisor y destinatario con NIF y domicilio.
        ->toContain('Audax Studio, S.L.')->toContain('B12345678')->toContain('Calle de Colón, 1')->toContain('46004 València')
        ->toContain($this->client->name.', S.L.')->toContain('B87654321')->toContain('Avenida del Puerto, 10')
        // L-06 descripción, unidades, precio sin impuesto y descuento; L-07 tipo, base y cuota por tipo.
        ->toContain('Desarrollo web')->toContain('15 h')->toContain('60,00 €')->toContain('5 %')->toContain('855,00 €')
        ->toContain('IVA 21 %')->toContain('179,55 €')->toContain('Exenta')
        // L-09 la referencia a la exención.
        ->toContain('Operación exenta de IVA (art. 20 de la Ley 37/1992).')
        // L-17 Registro Mercantil; totales; forma de pago; vencimiento; referencia del cliente; textos.
        ->toContain('Registro Mercantil de Valencia')->toContain('1.234,55 €')->toContain('Transferencia bancaria a la cuenta ES91 2100')
        ->toContain('Vencimiento')->toContain('03/02/2027')->toContain('PED-4529')->toContain('Proyecto de diciembre.')
        ->toContain('Gracias por confiar en Audax.')->toContain('Sus datos se tratan conforme al RGPD.')
        // Sin QR ni leyenda de VeriFactu en E1; con su hueco reservado.
        ->toContain('class="inv-qr"')->not->toContain('VERI*FACTU')->not->toContain('QR tributario');
});

it('a un empresario de la UE le pone su NIF-IVA y la mención de la inversión del sujeto pasivo, en inglés', function () {
    $client = invoicingClient(['country_code' => 'DE', 'tax_regime' => 'intra_eu', 'eu_vat_number' => 'DE123456789', 'language' => 'en', 'city' => 'Berlin', 'postal_code' => '10115', 'province' => null], ['tax_id' => null]);
    $invoice = invoicingIssue($this->admin, $client, [invoicingLine('10', '75', 'intra_eu', '0', 'UX audit')]);
    $html = (string) Storage::disk('local')->get((string) $invoice->pdf_path);

    expect($invoice->language)->toBe('en')
        ->and($html)->toContain('Invoice')->toContain('Issue date')->toContain('VAT ID DE123456789')->toContain('Not subject to Spanish VAT')
        ->toContain('Reverse charge (art. 196 of Directive 2006/112/EC).')->toContain('€750.00');
});

it('una rectificativa dice qué factura rectifica, cuándo y por qué', function () {
    $invoice = invoicingIssue($this->admin, $this->client);
    $credit = app(InvoiceCorrections::class)->cancel($invoice, $this->admin, 'Error en el cliente');
    $html = (string) Storage::disk('local')->get((string) SalesDocument::query()->findOrFail($credit->id)->pdf_path);

    expect($html)->toContain('Factura rectificativa')->toContain('CN270001')
        ->toContain('Rectifica la factura F270001 de 04/01/2027.')->toContain('Rectificación por el total (anulación)')
        ->toContain('Motivo: Error en el cliente')->toContain('−726,00 €');
});

it('la vista previa de un borrador lleva la marca «Borrador» y no tiene número', function () {
    $draft = invoicingDraft($this->admin, $this->client);

    $response = $this->actingAs($this->admin)->get("/facturacion/documentos/{$draft->id}/pdf")->assertOk();

    expect($response->getContent())->toContain('inv-mark')->toContain('Borrador')->not->toContain('F270001')
        ->and(SalesDocument::query()->find($draft->id)->pdf_path)->toBeNull();
});

it('el PDF archivado no se regenera: siempre es el mismo fichero con su SHA-256', function () {
    $invoice = invoicingIssue($this->admin, $this->client);
    $first = app(InvoicePdf::class)->archive($invoice);
    InvoiceDocumentSettings::put([...InvoiceDocumentSettings::get(), 'footer' => 'Pie nuevo']);

    expect(app(InvoicePdf::class)->archive(SalesDocument::query()->findOrFail($invoice->id)))->toBe($first)
        ->and(hash('sha256', $first))->toBe(SalesDocument::query()->find($invoice->id)->pdf_sha256);

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$invoice->id}/pdf?descargar=1")
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=F270001.html');
});
