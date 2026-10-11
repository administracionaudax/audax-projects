<?php

use App\Mail\SalesDocumentMail;
use App\Models\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Adjuntos de una factura propia (PLAN-EMISION §3.1, H-122; E2, D-444): con el sistema de adjuntos de
| siempre, internos o visibles para el cliente; los visibles van en el email y en el portal.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient(['billing_emails' => ['facturas@cliente.test']]);
    $this->invoice = invoicingIssue($this->admin, $this->client);
});

it('sube, descarga, cambia la visibilidad y borra; el visible va adjunto en el email', function () {
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/adjuntos", [
        'files' => [UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')],
        'client_visible' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/adjuntos", [
        'files' => [UploadedFile::fake()->create('notas.pdf', 20, 'application/pdf')],
    ])->assertRedirect();

    [$contract, $notes] = Attachment::query()->orderBy('id')->get()->all();
    expect($contract->client_visible)->toBeTrue()
        ->and($notes->client_visible)->toBeFalse()
        ->and($contract->project_id)->toBeNull()
        ->and($contract->path)->toStartWith("attachments/invoicing/{$this->invoice->id}/");

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->has('document.attachments', 2)->where('document.attachments.0.client_visible', true));
    $this->actingAs($this->admin)->get("/facturacion/documentos/{$this->invoice->id}/adjuntos/{$contract->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $compose = $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$this->invoice->id}/envio")->json();
    expect($compose['attachments'])->toBe(['F270001.pdf', 'contrato.pdf']);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", ['to' => ['facturas@cliente.test'], 'subject' => 'Factura', 'body' => 'Hola']);
    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => count($mail->attachments()) === 2);

    $this->actingAs($this->admin)->put("/facturacion/documentos/{$this->invoice->id}/adjuntos/{$contract->id}", ['client_visible' => false])->assertRedirect();
    expect($contract->refresh()->client_visible)->toBeFalse();

    $this->actingAs($this->admin)->delete("/facturacion/documentos/{$this->invoice->id}/adjuntos/{$notes->id}")->assertRedirect();
    expect(Attachment::query()->count())->toBe(1);
    Storage::disk('local')->assertMissing($notes->path);
});

it('un adjunto de otra factura da 404 y nadie sin la emisión lo descarga por la ruta de siempre', function () {
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/adjuntos", ['files' => [UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]]);
    $attachment = Attachment::query()->firstOrFail();
    $other = invoicingIssue($this->admin, $this->client);

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$other->id}/adjuntos/{$attachment->id}")->assertNotFound();
    $this->actingAs($this->admin)->delete("/facturacion/documentos/{$other->id}/adjuntos/{$attachment->id}")->assertNotFound();

    expect(userWithRole('employee')->can('view', $attachment))->toBeFalse()
        ->and($this->admin->can('view', $attachment))->toBeTrue()
        ->and($this->admin->can('delete', $attachment))->toBeFalse();
});
