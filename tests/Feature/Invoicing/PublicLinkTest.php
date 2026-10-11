<?php

use App\Domain\Billing\Issuing\InvoiceLinks;
use App\Domain\Tasks\AttachmentStorage;
use App\Models\SalesDocumentEmail;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| El enlace firmado de la factura (PLAN-EMISION §3.4, H-098; E2, D-440): sin usuario, caduca a los
| 60 días, anota «Vista por el cliente» sin píxel, y nunca enseña nada interno. Caducado: 410 con
| una página que lo explica; manipulado: 403.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient(['billing_emails' => ['facturas@cliente.test']]);
    $this->invoice = invoicingIssue($this->admin, $this->client, null, ['internal_note' => 'Cliente que paga tarde']);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", ['to' => ['facturas@cliente.test'], 'subject' => 'Factura', 'body' => 'Hola']);
    $this->email = SalesDocumentEmail::query()->firstOrFail();
    $this->link = InvoiceLinks::show($this->invoice, $this->email);
    auth()->logout();
});

it('el cliente abre su factura sin usuario y queda anotado que la ha visto (sin nada interno)', function () {
    $this->get($this->link)->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/invoice')
            ->where('problem', null)
            ->where('invoice.number', 'F270001')
            ->where('invoice.total', '726.00')
            ->where('invoice.collection', 'unpaid')
            ->missing('invoice.internal_note')
            ->where('invoice.pdf_url', fn (string $url) => str_contains($url, '/factura/'.$this->invoice->uuid.'/pdf') && str_contains($url, 'signature=')));

    $this->get($this->link)->assertOk();

    $this->invoice->refresh();
    $this->email->refresh();
    expect($this->invoice->view_count)->toBe(2)
        ->and($this->invoice->first_viewed_at)->not->toBeNull()
        ->and($this->email->view_count)->toBe(2)
        ->and($this->email->first_viewed_at)->not->toBeNull();

    // La página no enseña la nota interna en ningún sitio.
    expect($this->get($this->link)->getContent())->not->toContain('Cliente que paga tarde');
});

it('el PDF desde el enlace es el archivado; las visitas de la plantilla no cuentan', function () {
    $this->actingAs($this->admin)->get($this->link)->assertOk();
    expect($this->invoice->refresh()->view_count)->toBe(0);

    auth()->logout();
    $pdf = InvoiceLinks::pdf($this->invoice, $this->email->id, CarbonImmutable::now()->addDay(), true);
    $response = $this->get($pdf)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('F270001')
        ->and($this->invoice->refresh()->view_count)->toBe(1);
});

it('caducado: 410 con la explicación; manipulado: 403; un borrador o la emisión apagada: 404', function () {
    $this->travelTo(CarbonImmutable::parse('2027-04-03 10:00:00', 'Europe/Madrid'));
    $this->get($this->link)->assertStatus(410)->assertInertia(fn (Assert $page) => $page->where('problem', 'expired')->where('invoice', null));
    expect($this->invoice->refresh()->view_count)->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2027-02-02 10:00:00', 'Europe/Madrid'));
    $this->get(str_replace('envio='.$this->email->id, 'envio=999', $this->link))->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->where('problem', 'invalid'));
    $this->get(str_replace('/pdf?', '/pdf?x=1&', InvoiceLinks::pdf($this->invoice, null, CarbonImmutable::now()->addDay())))->assertForbidden();

    $draft = invoicingDraft($this->admin, $this->client);
    $this->get(InvoiceLinks::show($draft))->assertNotFound();

    enableInvoicing(false);
    $this->get($this->link)->assertNotFound();
});

it('solo sirve los adjuntos visibles para el cliente', function () {
    $storage = app(AttachmentStorage::class);
    $visible = $storage->store(UploadedFile::fake()->create('contrato.pdf', 5, 'application/pdf'), $this->invoice, null, $this->admin);
    $visible->forceFill(['client_visible' => true])->save();
    $internal = $storage->store(UploadedFile::fake()->create('notas.pdf', 5, 'application/pdf'), $this->invoice, null, $this->admin);
    $expires = CarbonImmutable::now()->addDay();

    $this->get($this->link)->assertInertia(fn (Assert $page) => $page->has('invoice.attachments', 1)->where('invoice.attachments.0.name', 'contrato.pdf'));
    $this->get(InvoiceLinks::attachment($this->invoice, $visible->id, null, $expires))->assertOk();
    $this->get(InvoiceLinks::attachment($this->invoice, $internal->id, null, $expires))->assertNotFound();
});
