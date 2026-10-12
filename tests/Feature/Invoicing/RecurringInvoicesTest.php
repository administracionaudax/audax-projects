<?php

use App\Domain\Billing\Recurring\RecurringInvoiceGenerator;
use App\Domain\Billing\Recurring\RecurringInvoices;
use App\Mail\SalesDocumentMail;
use App\Models\ClientBillingProfile;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRun;
use App\Models\SalesDocument;
use App\Models\SalesDocumentEmail;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Facturas recurrentes (PLAN-EMISION §3.1; E3): generar cada periodo con sus palabras dinámicas, en
| borrador por defecto, sin duplicar nunca (la tarea diaria y «Generar ahora»), omitir un periodo,
| pausar y reanudar, acabar, emitir y enviar sola, y nada automático en modo de prueba (D-239).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 07:30:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    ClientBillingProfile::query()->where('client_id', $this->client->id)->update(['billing_emails' => json_encode(['facturas@cliente.test'])]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function recurringOf(User $by, int $clientId, array $overrides = []): RecurringInvoice
{
    return app(RecurringInvoices::class)->save(null, [
        'name' => 'Fee mensual',
        'client_id' => $clientId,
        'periodicity' => 'monthly',
        'day_of_month' => 29,
        'start_date' => '2027-02-01',
        'mode' => 'draft',
        'series_id' => invoicingSeries('F')->id,
        'body' => 'Sprint de [mes] de [año]',
        'lines' => [invoicingLine('1', '1200', description: 'Fee de [mes] de [año] ([trimestre])')],
        ...$overrides,
    ], $by);
}

function runRecurringOn(string $day): array
{
    test()->travelTo(CarbonImmutable::parse($day.' 07:30:00', 'Europe/Madrid'));

    return app(RecurringInvoiceGenerator::class)->runDue();
}

it('genera el borrador de cada periodo con las palabras dinámicas, y nunca lo duplica', function () {
    $recurring = recurringOf($this->admin, $this->client->id);
    expect($recurring->next_run_on->toDateString())->toBe('2027-02-28');

    // Antes de la fecha, nada.
    expect(runRecurringOn('2027-02-27'))->toBe(['generated' => 0, 'skipped' => 0, 'failed' => 0]);

    expect(runRecurringOn('2027-02-28'))->toBe(['generated' => 1, 'skipped' => 0, 'failed' => 0]);
    $draft = SalesDocument::query()->where('recurring_invoice_id', $recurring->id)->firstOrFail();
    expect($draft->isDraft())->toBeTrue()
        ->and($draft->issue_date->toDateString())->toBe('2027-02-28')
        ->and($draft->recurring_period_on->toDateString())->toBe('2027-02-28')
        ->and($draft->body)->toBe('Sprint de febrero de 2027')
        ->and($draft->lines()->first()->description)->toBe('Fee de febrero de 2027 (1T)')
        ->and((string) $draft->subtotal)->toBe('1200.00')
        ->and((string) $draft->total)->toBe('1452.00');

    // La tarea otra vez el mismo día (o a la vez, en otro proceso): no duplica.
    expect(runRecurringOn('2027-02-28'))->toBe(['generated' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(SalesDocument::query()->where('recurring_invoice_id', $recurring->id)->count())->toBe(1)
        ->and(RecurringInvoiceRun::query()->count())->toBe(1);

    $recurring->refresh();
    expect($recurring->next_run_on->toDateString())->toBe('2027-03-29')
        ->and($recurring->occurrences_count)->toBe(1)
        ->and($recurring->last_document_id)->toBe($draft->id);

    // El mes siguiente, el suyo.
    runRecurringOn('2027-03-29');
    expect(SalesDocument::query()->where('recurring_invoice_id', $recurring->id)->orderByDesc('id')->firstOrFail()->lines()->first()->description)
        ->toBe('Fee de marzo de 2027 (1T)');
});

it('si un día no corrió, se pone al día con cada periodo pendiente (y solo una vez)', function () {
    $recurring = recurringOf($this->admin, $this->client->id, ['day_of_month' => 1, 'start_date' => '2027-02-01']);
    expect($recurring->next_run_on->toDateString())->toBe('2027-02-01');

    expect(runRecurringOn('2027-03-02'))->toBe(['generated' => 2, 'skipped' => 0, 'failed' => 0])
        ->and(RecurringInvoiceRun::query()->orderBy('period_on')->pluck('period_on')->map->toDateString()->all())->toBe(['2027-02-01', '2027-03-01'])
        ->and($recurring->refresh()->next_run_on->toDateString())->toBe('2027-04-01');
});

it('«Omitir este periodo» no lo genera y no gasta una vez; se puede deshacer', function () {
    $recurring = recurringOf($this->admin, $this->client->id, ['max_occurrences' => 2]);
    $service = app(RecurringInvoices::class);
    $service->skip($recurring, CarbonImmutable::parse('2027-02-28'), true, $this->admin);

    expect(fn () => $service->skip($recurring->refresh(), CarbonImmutable::parse('2027-02-27'), true, $this->admin))->toThrow(ValidationException::class);

    expect(runRecurringOn('2027-02-28'))->toBe(['generated' => 0, 'skipped' => 1, 'failed' => 0])
        ->and(SalesDocument::query()->count())->toBe(0)
        ->and(RecurringInvoiceRun::query()->value('status'))->toBe('skipped');

    $recurring->refresh();
    expect($recurring->next_run_on->toDateString())->toBe('2027-03-29')
        ->and($recurring->occurrences_count)->toBe(0);

    // Deshacer el siguiente antes de que llegue.
    $service->skip($recurring, CarbonImmutable::parse('2027-03-29'), true, $this->admin);
    $service->skip($recurring->refresh(), CarbonImmutable::parse('2027-03-29'), false, $this->admin);
    expect($recurring->refresh()->skipped_dates)->toBe(['2027-02-28']);

    runRecurringOn('2027-03-29');
    runRecurringOn('2027-04-29');
    // Dos generadas (el omitido no cuenta): se acaba.
    $recurring->refresh();
    expect($recurring->occurrences_count)->toBe(2)
        ->and($recurring->next_run_on)->toBeNull()
        ->and($recurring->finished_at)->not->toBeNull()
        ->and($recurring->state())->toBe('finished');
});

it('en pausa no genera; al reanudar, la próxima es la primera desde hoy (lo de la pausa no se genera)', function () {
    $recurring = recurringOf($this->admin, $this->client->id);
    $service = app(RecurringInvoices::class);
    $service->pause($recurring, $this->admin);

    expect(runRecurringOn('2027-02-28'))->toBe(['generated' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(runRecurringOn('2027-03-29'))->toBe(['generated' => 0, 'skipped' => 0, 'failed' => 0]);

    $this->travelTo(CarbonImmutable::parse('2027-04-10 10:00:00', 'Europe/Madrid'));
    $service->resume($recurring->refresh(), $this->admin);
    expect($recurring->refresh()->next_run_on->toDateString())->toBe('2027-04-29')
        ->and($recurring->state())->toBe('active');
});

it('se acaba en su fecha de fin', function () {
    $recurring = recurringOf($this->admin, $this->client->id, ['end_date' => '2027-03-31']);

    runRecurringOn('2027-02-28');
    runRecurringOn('2027-03-29');
    expect($recurring->refresh()->next_run_on)->toBeNull()
        ->and(SalesDocument::query()->count())->toBe(2);
    expect(runRecurringOn('2027-04-29'))->toBe(['generated' => 0, 'skipped' => 0, 'failed' => 0]);
});

it('en modo «Emitir» la emite en nombre de quien lo eligió y, si se pidió, la envía', function () {
    $recurring = recurringOf($this->admin, $this->client->id, ['mode' => 'issue', 'auto_send' => true]);
    expect($recurring->issue_as)->toBe($this->admin->id);

    runRecurringOn('2027-02-28');
    $invoice = SalesDocument::query()->where('recurring_invoice_id', $recurring->id)->firstOrFail();
    expect($invoice->status->value)->toBe('issued')
        ->and($invoice->full_number)->toBe('F270001')
        ->and($invoice->issued_by)->toBe($this->admin->id)
        ->and($invoice->auto_issue_error)->toBeNull();
    expect(SalesDocumentEmail::query()->where('sales_document_id', $invoice->id)->value('to'))->toBe(['facturas@cliente.test']);
    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('facturas@cliente.test'));
});

it('si no se puede emitir, se queda en borrador con el motivo (y no se envía)', function () {
    $bare = invoicingClient(['address' => null], ['name' => 'Sin dirección']);
    $recurring = recurringOf($this->admin, $bare->id, ['mode' => 'issue', 'auto_send' => true]);

    runRecurringOn('2027-02-28');
    $draft = SalesDocument::query()->where('recurring_invoice_id', $recurring->id)->firstOrFail();
    expect($draft->isDraft())->toBeTrue()
        ->and($draft->auto_issue_error)->toContain('No se pudo emitir sola')
        ->and(RecurringInvoiceRun::query()->value('error'))->toContain('No se pudo emitir sola');
    Mail::assertNothingSent();
});

it('en modo de prueba la tarea diaria no genera nada; «Generar ahora» sí', function () {
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);
    $recurring = recurringOf($this->admin, $this->client->id);

    $this->travelTo(CarbonImmutable::parse('2027-02-28 07:30:00', 'Europe/Madrid'));
    $this->artisan('app:billing-generate-recurring')->assertSuccessful();
    expect(SalesDocument::query()->count())->toBe(0);

    // A mano, ya (con la fecha de hoy y las palabras de su periodo).
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00', 'Europe/Madrid'));
    $run = app(RecurringInvoiceGenerator::class)->generateNow($recurring, $this->admin);
    expect($run->trigger)->toBe('manual')
        ->and($run->period_on->toDateString())->toBe('2027-02-28')
        ->and($run->document->issue_date->toDateString())->toBe('2027-02-10')
        ->and($run->document->lines()->first()->description)->toBe('Fee de febrero de 2027 (1T)')
        ->and($recurring->refresh()->next_run_on->toDateString())->toBe('2027-03-29');

    // Encendida de verdad, la tarea genera; el periodo ya generado no se repite.
    enableInvoicing();
    $this->travelTo(CarbonImmutable::parse('2027-03-29 07:30:00', 'Europe/Madrid'));
    $this->artisan('app:billing-generate-recurring')->assertSuccessful();
    expect(SalesDocument::query()->count())->toBe(2)
        ->and(RecurringInvoiceRun::query()->count())->toBe(2);
});

it('«Generar ahora» se salta los periodos omitidos y no deja generar una acabada', function () {
    $recurring = recurringOf($this->admin, $this->client->id, ['max_occurrences' => 1]);
    app(RecurringInvoices::class)->skip($recurring, CarbonImmutable::parse('2027-02-28'), true, $this->admin);

    $run = app(RecurringInvoiceGenerator::class)->generateNow($recurring->refresh(), $this->admin);
    expect($run->period_on->toDateString())->toBe('2027-03-29')
        ->and(RecurringInvoiceRun::query()->count())->toBe(2);

    expect(fn () => app(RecurringInvoiceGenerator::class)->generateNow($recurring->refresh(), $this->admin))->toThrow(ValidationException::class);
});

it('borrar solo una que no ha generado nada; con facturas, se pausa', function () {
    $recurring = recurringOf($this->admin, $this->client->id);
    app(RecurringInvoiceGenerator::class)->generateNow($recurring, $this->admin);

    expect(fn () => app(RecurringInvoices::class)->delete($recurring->refresh(), $this->admin))->toThrow(ValidationException::class);

    $other = recurringOf($this->admin, $this->client->id, ['name' => 'Otra']);
    app(RecurringInvoices::class)->delete($other, $this->admin);
    expect(RecurringInvoice::query()->count())->toBe(1);
});
