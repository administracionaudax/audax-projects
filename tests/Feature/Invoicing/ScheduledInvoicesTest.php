<?php

use App\Domain\Billing\Recurring\ScheduledInvoices;
use App\Mail\SalesDocumentMail;
use App\Models\ClientBillingProfile;
use App\Models\SalesDocument;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Facturas programadas (PLAN-EMISION §3.1; E3): un borrador con fecha de emisión futura que se
| emite solo ese día por la mañana (y se envía, si se eligió), en nombre de quien la programó. Solo
| con la emisión encendida de verdad (D-239); lo que no se puede emitir sigue programado con el motivo.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    ClientBillingProfile::query()->where('client_id', $this->client->id)->update(['billing_emails' => json_encode(['facturas@cliente.test'])]);
    $this->draft = invoicingDraft($this->admin, $this->client, null, ['due_date' => '2027-03-03']);
});

function issueScheduledOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 08:00:00', 'Europe/Madrid'));
    test()->artisan('app:billing-issue-scheduled')->assertSuccessful();
}

it('se emite sola el día programado, con esa fecha, y se envía si se eligió', function () {
    app(ScheduledInvoices::class)->schedule($this->draft, CarbonImmutable::parse('2027-02-10'), true, $this->admin);
    $this->draft->refresh();
    expect($this->draft->isScheduled())->toBeTrue()
        ->and($this->draft->issue_date->toDateString())->toBe('2027-02-10')
        // El vencimiento se mueve lo mismo (30 días).
        ->and($this->draft->due_date->toDateString())->toBe('2027-03-12');

    issueScheduledOn('2027-02-09');
    expect($this->draft->refresh()->isDraft())->toBeTrue();

    issueScheduledOn('2027-02-10');
    $this->draft->refresh();
    expect($this->draft->status->value)->toBe('issued')
        ->and($this->draft->full_number)->toBe('F270001')
        ->and($this->draft->issue_date->toDateString())->toBe('2027-02-10')
        ->and($this->draft->issued_by)->toBe($this->admin->id);
    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('facturas@cliente.test'));

    // Otra vez la tarea: nada más.
    issueScheduledOn('2027-02-10');
    expect(SalesDocument::query()->where('status', 'issued')->count())->toBe(1);
});

it('la fecha tiene que ser futura; se desprograma y queda en borrador', function () {
    $service = app(ScheduledInvoices::class);
    expect(fn () => $service->schedule($this->draft, CarbonImmutable::parse('2027-02-01'), false, $this->admin))->toThrow(ValidationException::class);

    $service->schedule($this->draft, CarbonImmutable::parse('2027-02-05'), false, $this->admin);
    $service->unschedule($this->draft->refresh(), $this->admin);
    expect($this->draft->refresh()->isScheduled())->toBeFalse();

    issueScheduledOn('2027-02-05');
    expect($this->draft->refresh()->isDraft())->toBeTrue();
});

it('si un día no corrió, se emite con la fecha del día en que corre', function () {
    app(ScheduledInvoices::class)->schedule($this->draft, CarbonImmutable::parse('2027-02-06'), false, $this->admin);
    issueScheduledOn('2027-02-08');

    expect($this->draft->refresh()->issue_date->toDateString())->toBe('2027-02-08')
        ->and($this->draft->status->value)->toBe('issued');
    Mail::assertNothingSent();
});

it('si no se puede emitir, sigue programada con el motivo y lo intenta al día siguiente', function () {
    app(ScheduledInvoices::class)->schedule($this->draft, CarbonImmutable::parse('2027-02-10'), false, $this->admin);
    Setting::set('billing_issuer', [...(array) Setting::get('billing_issuer'), 'tax_id' => null]);

    issueScheduledOn('2027-02-10');
    $this->draft->refresh();
    expect($this->draft->isScheduled())->toBeTrue()
        ->and($this->draft->auto_issue_error)->toContain('No se pudo emitir sola');

    invoicingIssuer();
    issueScheduledOn('2027-02-11');
    expect($this->draft->refresh()->status->value)->toBe('issued')
        ->and($this->draft->auto_issue_error)->toBeNull();
});

it('en modo de prueba no se emite nada sola; «Emitir ahora» sí', function () {
    app(ScheduledInvoices::class)->schedule($this->draft, CarbonImmutable::parse('2027-02-10'), true, $this->admin);
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);

    issueScheduledOn('2027-02-10');
    expect($this->draft->refresh()->isDraft())->toBeTrue();
    Mail::assertNothingSent();

    // Adelantarla a mano: se emite con la fecha de hoy (la programada sería futura).
    $this->travelTo(CarbonImmutable::parse('2027-02-10 12:00:00', 'Europe/Madrid'));
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->draft->id}/emitir")->assertSessionHasNoErrors()->assertRedirect();
    expect($this->draft->refresh()->status->value)->toBe('issued');
});
