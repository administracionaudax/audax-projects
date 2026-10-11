<?php

use App\Domain\Billing\Issuing\InvoiceEmailSettings;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\EmailTemplate;
use App\Models\Payment;
use App\Models\SalesDocumentEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Permisos de E2 por rol (PLAN-EMISION §3.2; D-418, D-436 a D-444 y D-245): cobrar, anular un cobro,
| enviar y recordar (manage-billing: admin y finanzas con el permiso); adjuntos (quien prepara,
| use-invoicing); Ajustes del correo y los recordatorios (verlos con use-invoicing, cambiarlos con
| manage-billing). Responsables, plantilla, colaboradores, clientes del portal y el admin excluido de
| Facturación, nada.
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

    $this->finance = userWithRole('employee');
    $this->finance->givePermissionTo([Permission::ViewFinancials->value, Permission::ManageBilling->value]);
    $this->preparer = userWithRole('employee');
    $this->preparer->givePermissionTo(Permission::ViewFinancials->value);
    $this->excluded = userWithRole('admin');
    AppModules::setExcluded(AppModule::Billing, [$this->excluded->id]);
});

function e2Requests(int $id, ?int $payment = null, ?int $attachment = null): array
{
    return [
        'cobrar' => ['post', "/facturacion/documentos/{$id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '10', 'method' => 'cash']],
        'anular cobro' => ['post', "/facturacion/documentos/{$id}/cobros/".($payment ?? 0).'/anular', ['reason' => 'Error al registrarlo']],
        'componer' => ['getJson', "/facturacion/documentos/{$id}/envio", []],
        'enviar' => ['post', "/facturacion/documentos/{$id}/envios", ['to' => ['facturas@cliente.test'], 'subject' => 'Factura', 'body' => 'Hola']],
        'recordar' => ['post', "/facturacion/documentos/{$id}/envios", ['to' => ['facturas@cliente.test'], 'subject' => 'Recordatorio', 'body' => 'Hola', 'reminder' => true]],
        'adjuntar' => ['post', "/facturacion/documentos/{$id}/adjuntos", ['files' => [UploadedFile::fake()->create('contrato.pdf', 5, 'application/pdf')]]],
        'adjunto visible' => ['put', "/facturacion/documentos/{$id}/adjuntos/".($attachment ?? 0), ['client_visible' => true]],
        'remitente' => ['put', '/facturacion/ajustes/emision/correo', ['sender_name' => 'Audax']],
        'plantilla' => ['put', '/facturacion/ajustes/emision/plantillas/invoice/es', ['subject' => 'Factura [numero]', 'body' => 'Hola']],
        'escalón' => ['post', '/facturacion/ajustes/emision/recordatorios', ['days' => 45]],
    ];
}

function e2Status(User $user, array $request): int
{
    [$method, $url, $data] = $request;

    return test()->actingAs($user)->{$method}($url, $data)->status();
}

it('admin y finanzas con manage-billing cobran, envían, recuerdan, adjuntan y cambian los ajustes', function (string $who) {
    $user = $who === 'admin' ? $this->admin : $this->finance;
    $payment = Payment::query()->create(['sales_document_id' => $this->invoice->id, 'paid_on' => '2027-02-01', 'amount' => '1', 'method' => 'cash']);
    $attachment = Attachment::query()->create(['attachable_type' => $this->invoice->getMorphClass(), 'attachable_id' => $this->invoice->id, 'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);

    foreach (e2Requests($this->invoice->id, $payment->id, $attachment->id) as $name => $request) {
        expect(e2Status($user, $request))->toBeIn([200, 302], "{$who}: {$name}");
    }

    expect(Payment::query()->where('amount', '10.00')->exists())->toBeTrue()
        ->and(SalesDocumentEmail::query()->count())->toBe(2)
        ->and(InvoiceEmailSettings::get()['sender_name'])->toBe('Audax')
        ->and(EmailTemplate::query()->count())->toBe(1);
})->with(['admin', 'finanzas']);

it('quien solo prepara (view-billing) adjunta y ve los ajustes, pero no cobra, ni envía, ni los cambia', function () {
    $attachment = Attachment::query()->create(['attachable_type' => $this->invoice->getMorphClass(), 'attachable_id' => $this->invoice->id, 'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
    $requests = e2Requests($this->invoice->id, null, $attachment->id);

    foreach (['cobrar', 'componer', 'enviar', 'recordar', 'remitente', 'plantilla', 'escalón'] as $name) {
        expect(e2Status($this->preparer, $requests[$name]))->toBe(403, $name);
    }
    expect(e2Status($this->preparer, $requests['adjuntar']))->toBe(302)
        ->and(e2Status($this->preparer, $requests['adjunto visible']))->toBe(302);

    $this->actingAs($this->preparer)->get('/facturacion/ajustes?apartado=correo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mailing.can_manage', false)->has('mailing.templates', 6)->has('mailing.rules', 4));
    $this->actingAs($this->preparer)->get("/facturacion/documentos/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->where('actions', fn ($actions) => ! collect($actions)->contains('record_payment') && collect($actions)->contains('attach'))->where('payment_methods', []));
});

it('responsables, plantilla, colaboradores, clientes del portal y el admin excluido no tocan nada', function () {
    $users = [
        'responsable' => userWithRole('department_manager'),
        'empleado' => userWithRole('employee'),
        'colaborador' => User::factory()->collaborator()->create(),
        'portal' => User::factory()->portalOf($this->client)->create(),
        'admin excluido' => $this->excluded,
    ];

    foreach ($users as $who => $user) {
        foreach (e2Requests($this->invoice->id) as $name => $request) {
            expect(e2Status($user, $request))->toBeIn([302, 403, 404], "{$who}: {$name}");
        }
        expect(e2Status($user, ['get', '/facturacion/facturas?vista=por-cobrar', []]))->toBeIn([302, 403, 404], "{$who}: por cobrar");
    }

    expect(Payment::query()->count())->toBe(0)
        ->and(SalesDocumentEmail::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('«Por cobrar» lleva a la vista de Facturas con la antigüedad por cliente', function () {
    $this->actingAs($this->admin)->get('/facturacion/por-cobrar?cliente='.$this->client->id)
        ->assertRedirect('/facturacion/facturas?cliente='.$this->client->id.'&vista=por-cobrar');

    $this->travelTo(CarbonImmutable::parse('2027-04-10 10:00:00', 'Europe/Madrid'));
    invoicingIssue($this->admin, $this->client, null, ['issue_date' => '2027-04-10']);

    $this->actingAs($this->admin)->get('/facturacion/facturas?vista=por-cobrar')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('aging.rows.0.client.id', $this->client->id)
            ->where('aging.rows.0.buckets.current', '726.00')
            ->where('aging.rows.0.buckets.d31_60', '726.00')
            ->where('aging.total', '1452.00'));

    $this->actingAs($this->admin)->get('/facturacion/facturas?vista=por-cobrar&antiguedad=31-60')
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', 'F270001')->where('totals.pending', '726.00'));
    $this->actingAs($this->admin)->get('/facturacion/facturas')->assertInertia(fn (Assert $page) => $page->where('aging', null));
});
