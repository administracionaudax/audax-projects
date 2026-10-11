<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Tasks\AttachmentStorage;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Portal · Facturas (PLAN-EMISION §3.5; E2, D-443): el cliente ve sus facturas propias emitidas (y
| sus rectificativas), su estado de cobro y su PDF. Nunca las de otro cliente, borradores, anuladas
| por error, de la serie de pruebas ni nada interno. Apagado por cliente (por defecto) y en modo de
| prueba, nada.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient(['portal_invoices' => true]);
    $this->portal = User::factory()->portalOf($this->client)->create();

    $this->paid = invoicingIssue($this->admin, $this->client, null, ['internal_note' => 'Nota interna secreta']);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->paid->id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '300', 'method' => 'transfer']);
    $this->open = invoicingIssue($this->admin, $this->client);
    $this->draft = invoicingDraft($this->admin, $this->client);
    $this->test = invoicingIssue($this->admin, $this->client, null, ['series_id' => invoicingSeries('PRU')->id]);
    $this->voided = invoicingIssue($this->admin, $this->client);
    app(InvoiceCorrections::class)->void($this->voided, $this->admin, 'Expedida por error');

    $other = invoicingClient(['portal_invoices' => true], ['name' => 'Otro cliente']);
    $this->foreign = invoicingIssue($this->admin, $other);
});

it('ve sus facturas emitidas con su estado, lo pendiente y nada interno', function () {
    $this->actingAs($this->portal)->get('/portal/facturas')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/invoices/index')
            ->has('invoices', 2)
            ->where('invoices.0.number', 'F270002')
            ->where('invoices.0.collection', 'unpaid')
            ->where('invoices.1.number', 'F270001')
            ->where('invoices.1.collection', 'partial')
            ->where('invoices.1.pending', '426.00')
            ->missing('invoices.1.internal_note')
            ->where('summary.pending', '1152.00'));

    expect($this->actingAs($this->portal)->get('/portal/facturas')->getContent())->not->toContain('Nota interna secreta')
        ->and($this->actingAs($this->portal)->get('/portal')->inertiaProps('portal.invoices'))->toBeTrue();
});

it('descarga su PDF y nunca el de otro cliente, un borrador, una de prueba ni una anulada por error', function () {
    $this->actingAs($this->portal)->get("/portal/facturas/{$this->paid->id}/pdf")->assertOk();
    expect($this->paid->refresh()->view_count)->toBe(1);

    foreach ([$this->foreign, $this->draft, $this->test, $this->voided] as $document) {
        $this->actingAs($this->portal)->get("/portal/facturas/{$document->id}/pdf")->assertNotFound();
    }
});

it('una anulada sale con su rectificativa, como anulada', function () {
    $credit = app(InvoiceCorrections::class)->cancel($this->open, $this->admin, 'Error en el concepto');

    $this->actingAs($this->portal)->get('/portal/facturas')
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices', 3)
            ->where('invoices.0.number', $credit->full_number)
            ->where('invoices.0.type', 'credit_note')
            ->where('invoices.1.collection', 'cancelled'));
});

it('solo los adjuntos visibles para el cliente', function () {
    $storage = app(AttachmentStorage::class);
    $visible = $storage->store(UploadedFile::fake()->create('albaran.pdf', 5, 'application/pdf'), $this->paid, null, $this->admin);
    $visible->forceFill(['client_visible' => true])->save();
    $internal = $storage->store(UploadedFile::fake()->create('interno.pdf', 5, 'application/pdf'), $this->paid, null, $this->admin);

    $this->actingAs($this->portal)->get('/portal/facturas')
        ->assertInertia(fn (Assert $page) => $page->where('invoices.1.attachments', [['id' => $visible->id, 'name' => 'albaran.pdf', 'size' => $visible->size]]));
    $this->actingAs($this->portal)->get("/portal/facturas/{$this->paid->id}/adjuntos/{$visible->id}")->assertOk();
    $this->actingAs($this->portal)->get("/portal/facturas/{$this->paid->id}/adjuntos/{$internal->id}")->assertNotFound();
    $this->actingAs($this->portal)->get("/portal/facturas/{$this->foreign->id}/adjuntos/{$visible->id}")->assertNotFound();
});

it('apagado para el cliente, con la emisión apagada o en modo de prueba: 404 y sin «Facturas»', function () {
    $this->client->billingProfile()->update(['portal_invoices' => false]);
    $this->actingAs($this->portal)->get('/portal/facturas')->assertNotFound();
    expect($this->actingAs($this->portal)->get('/portal')->inertiaProps('portal.invoices'))->toBeFalse();

    $this->client->billingProfile()->update(['portal_invoices' => true]);
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);
    $this->actingAs($this->portal)->get('/portal/facturas')->assertNotFound();
    $this->actingAs($this->portal)->get("/portal/facturas/{$this->paid->id}/pdf")->assertNotFound();
});

it('la ficha de facturación del cliente las enciende (y los recordatorios), con view-billing', function () {
    $other = invoicingClient([], ['name' => 'Tercero']);

    $this->actingAs($this->admin)->put("/clientes/{$other->id}/facturacion/opciones", ['portal_invoices' => true, 'payment_reminders' => false])->assertRedirect();
    expect($other->billingProfile()->first()->only(['portal_invoices', 'payment_reminders']))->toBe(['portal_invoices' => true, 'payment_reminders' => false]);

    $this->actingAs(userWithRole('employee'))->put("/clientes/{$other->id}/facturacion/opciones", ['portal_invoices' => false, 'payment_reminders' => true])->assertForbidden();
    $this->actingAs($this->portal)->put("/clientes/{$other->id}/facturacion/opciones", ['portal_invoices' => false, 'payment_reminders' => true])->assertForbidden();
});
