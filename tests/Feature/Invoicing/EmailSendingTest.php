<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\InvoiceEmailSettings;
use App\Jobs\SendSalesDocumentEmail;
use App\Mail\SalesDocumentMail;
use App\Models\EmailTemplate;
use App\Models\HoldedContact;
use App\Models\SalesDocumentEmail;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Envío de una factura propia por email (PLAN-EMISION §3.4, E2; D-438 a D-441): el diálogo propone los
| destinatarios y la plantilla con sus variables; el envío va por la cola `mail` con el PDF archivado
| y los adjuntos visibles, desde la dirección del relé con el nombre de Ajustes, y queda en la
| historia. En modo de prueba (D-239) solo le llega a quien lo envía. Nunca sale un email real.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    config(['mail.from.address' => 'administracion@audaxstudio.com']);
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin', ['email' => 'ana@audax.test']);
    $this->client = invoicingClient(['billing_emails' => ['facturas@cliente.test']], ['contact_name' => 'Lucía', 'contact_email' => 'lucia@cliente.test']);
    $this->invoice = invoicingIssue($this->admin, $this->client);
});

function sendData(array $overrides = []): array
{
    return ['to' => ['facturas@cliente.test'], 'cc' => ['lucia@cliente.test'], 'subject' => 'Factura [numero]', 'body' => "Hola:\n\nVa la factura [numero] por [importe].\n\nAquí: [enlace]", ...$overrides];
}

it('el diálogo propone los emails del cliente y la plantilla con las variables puestas (salvo el enlace)', function () {
    HoldedContact::query()->create(['holded_id' => 'h1', 'name' => 'Administración Cliente', 'email' => 'admin@cliente.test', 'client_id' => $this->client->id]);

    $compose = $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$this->invoice->id}/envio")->assertOk()->json();

    expect($compose['to'])->toBe(['facturas@cliente.test'])
        ->and(array_column($compose['suggestions'], 'email'))->toBe(['facturas@cliente.test', 'lucia@cliente.test', 'admin@cliente.test'])
        ->and($compose['subject'])->toBe('Factura F270001 de Audax Studio')
        ->and($compose['body'])->toContain('la factura F270001 por 726,00 €, con vencimiento el 03/03/2027')
        ->and($compose['attachments'])->toBe(['F270001.pdf'])
        ->and($compose['from'])->toBe(['name' => 'Audax Studio', 'email' => 'administracion@audaxstudio.com'])
        ->and($compose['test_mode'])->toBeFalse();
});

it('envía por la cola mail con el PDF, CC y copia oculta de Ajustes, y queda en la historia', function () {
    Mail::fake();
    InvoiceEmailSettings::put(['sender_name' => 'Audax Studio · Administración', 'reply_to' => 'cobros@audaxstudio.com', 'bcc' => 'copia@audaxstudio.com']);

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData())->assertSessionHasNoErrors()->assertRedirect();

    Mail::assertSent(SalesDocumentMail::class, function (SalesDocumentMail $mail): bool {
        return $mail->hasTo('facturas@cliente.test') && $mail->hasCc('lucia@cliente.test') && $mail->hasBcc('copia@audaxstudio.com')
            && $mail->hasFrom('administracion@audaxstudio.com', 'Audax Studio · Administración')
            && $mail->hasReplyTo('cobros@audaxstudio.com')
            && $mail->hasSubject('Factura F270001')
            && count($mail->attachments()) === 1;
    });

    $email = SalesDocumentEmail::query()->firstOrFail();
    $this->invoice->refresh();
    expect($email->status)->toBe('sent')
        ->and($email->body)->toContain('por 726,00 €')
        ->and($email->body)->toContain('/factura/'.$this->invoice->uuid.'?envio='.$email->id)
        ->and($email->pdf_sha256)->toBe($this->invoice->pdf_sha256)
        ->and($email->bcc)->toBe(['copia@audaxstudio.com'])
        ->and($this->invoice->sent_at)->not->toBeNull();

    $this->get("/facturacion/documentos/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->where('document.emails.0.status', 'sent')->where('document.emails.0.to', ['facturas@cliente.test'])->where('document.sent_at', fn ($value) => $value !== null));

    // Enviada, ya no se puede anular su registro (lo recibió el cliente).
    expect(InvoiceCorrections::voidProblems($this->invoice))->toBe([__('invoicing.errors.void_sent')]);
});

it('encola el envío en mail (Queue::fake) sin mandar nada aún', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData())->assertRedirect();

    Queue::assertPushedOn('mail', SendSalesDocumentEmail::class);
    expect(SalesDocumentEmail::query()->value('status'))->toBe('queued')
        ->and($this->invoice->refresh()->sent_at)->toBeNull();
});

it('usa la plantilla cambiada en Ajustes y la del idioma del cliente', function () {
    EmailTemplate::query()->create(['kind' => 'invoice', 'language' => 'en', 'subject' => 'Invoice [numero] — [cliente]', 'body' => 'Total [importe]']);
    $english = invoicingClient(['language' => 'en'], ['name' => 'Acme']);
    $invoice = invoicingIssue($this->admin, $english);

    $compose = $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$invoice->id}/envio")->json();
    expect($compose['language'])->toBe('en')
        ->and($compose['subject'])->toBe('Invoice F270002 — Acme, S.L.')
        ->and($compose['body'])->toBe('Total €726.00');
});

it('en modo de prueba solo le llega a quien lo envía, nunca al cliente', function () {
    Mail::fake();
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);

    expect($this->actingAs($this->admin)->getJson("/facturacion/documentos/{$this->invoice->id}/envio")->json('test_mode'))->toBeTrue();
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData())->assertRedirect();

    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('ana@audax.test') && ! $mail->hasTo('facturas@cliente.test') && ! $mail->hasCc('lucia@cliente.test'));
    Mail::assertNotSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('facturas@cliente.test'));
    expect(SalesDocumentEmail::query()->value('test_mode'))->toBeTruthy()
        ->and($this->invoice->refresh()->sent_at)->toBeNull();
});

it('si el correo falla, queda «no enviado» con el error y se puede volver a enviar', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData())->assertRedirect();

    $email = SalesDocumentEmail::query()->firstOrFail();
    expect($email->status)->toBe('failed')
        ->and($email->error)->toBe('Connection refused')
        ->and($this->invoice->refresh()->sent_at)->toBeNull()
        ->and(InvoiceCorrections::voidProblems($this->invoice))->not->toContain(__('invoicing.errors.void_sent'));
});

it('valida los destinatarios y no envía borradores, anuladas ni sin permiso', function () {
    Mail::fake();

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData(['to' => ['no-es-un-email']]))->assertSessionHasErrors('to.0');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData(['to' => []]))->assertSessionHasErrors('to');

    $draft = invoicingDraft($this->admin, $this->client);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$draft->id}/envios", sendData())->assertForbidden();
    $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$draft->id}/envio")->assertForbidden();

    app(InvoiceCorrections::class)->cancel($this->invoice, $this->admin, 'Factura duplicada');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", sendData())->assertForbidden();

    Mail::assertNothingSent();
});

it('el recordatorio a mano usa su plantilla y solo con algo pendiente', function () {
    Mail::fake();

    $compose = $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$this->invoice->id}/envio?recordatorio=1")->assertOk()->json();
    expect($compose['kind'])->toBe('reminder')
        ->and($compose['subject'])->toBe('Recordatorio: factura F270001 pendiente de pago')
        ->and($compose['body'])->toContain('tiene 726,00 € pendiente de pago');

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", [...sendData(), 'reminder' => true])->assertRedirect();
    expect(SalesDocumentEmail::query()->value('kind'))->toBe('reminder');

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '726', 'method' => 'transfer']);
    $this->actingAs($this->admin)->getJson("/facturacion/documentos/{$this->invoice->id}/envio?recordatorio=1")->assertStatus(422);
});

it('el email escapa el texto y convierte el enlace en un enlace con nombre', function () {
    $paragraphs = SalesDocumentMail::paragraphs("Hola <b>*cliente*</b>:\n\nMírala aquí: https://ejemplo.test/factura/x?a=1&b=2 y gracias", 'Ver la factura');

    expect($paragraphs[0])->toBe('Hola &lt;b&gt;\*cliente\*&lt;/b&gt;:')
        ->and($paragraphs[1])->toBe('Mírala aquí: <a href="https://ejemplo.test/factura/x?a=1&amp;b=2">Ver la factura</a> y gracias');

    $html = (new SalesDocumentMail('Factura', "Hola:\n\nTexto", 'https://ejemplo.test/f', 'es', false, false, []))->render();
    // Cabecera y pie con el nombre del emisor (lo recibe el cliente), no el de la app.
    expect($html)->toContain('Ver y descargar la factura')->toContain('https://ejemplo.test/f')->toContain('El PDF va adjunto')
        ->toContain('© '.date('Y').' Audax Studio')->not->toContain('© '.date('Y').' Audax Proyectos');
});

it('Ajustes: cambiar una plantilla, volver a la de serie y el remitente, en la auditoría', function () {
    $this->actingAs($this->admin)->put('/facturacion/ajustes/emision/plantillas/reminder/es', ['subject' => 'Te recordamos la [numero]', 'body' => 'Pendiente: [pendiente]'])->assertRedirect();
    expect(EmailTemplate::query()->where('kind', 'reminder')->value('subject'))->toBe('Te recordamos la [numero]');

    $this->actingAs($this->admin)->delete('/facturacion/ajustes/emision/plantillas/reminder/es')->assertRedirect();
    expect(EmailTemplate::query()->count())->toBe(0);

    $this->actingAs($this->admin)->put('/facturacion/ajustes/emision/correo', ['sender_name' => '', 'reply_to' => 'no-es-email', 'bcc' => null])->assertSessionHasErrors('reply_to');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/emision/correo', ['sender_name' => '', 'reply_to' => 'Cobros@AudaxStudio.com', 'bcc' => null])->assertSessionHasNoErrors();
    expect(InvoiceEmailSettings::get())->toBe(['sender_name' => 'Audax Studio', 'reply_to' => 'cobros@audaxstudio.com', 'bcc' => null])
        ->and(Activity::query()->where('log_name', 'settings')->count())->toBe(3);
});
