<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\InvoiceList;
use App\Mail\SalesDocumentMail;
use App\Models\BillingDocument;
use App\Models\PaymentReminderDelivery;
use App\Models\PaymentReminderRule;
use App\Models\SalesDocumentEmail;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Recordatorios de cobro por escalones (PLAN-EMISION §3.3, H-092; E2, D-442): con el tiempo
| (travelTo) salen el de 3 días antes, el del día, y el de 7 y 30 días después, uno por escalón;
| nunca a una cobrada o anulada, ni a un cliente que los tiene apagados; y en modo de prueba no sale
| nada (D-239). Los que no salen van a «Requiere atención».
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient(['billing_emails' => ['facturas@cliente.test']]);
    // Vence el 03/03/2027 (30 días).
    $this->invoice = invoicingIssue($this->admin, $this->client);
});

function remindOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse("{$day} 09:00:00", 'Europe/Madrid'));
    test()->artisan('app:billing-send-reminders')->assertSuccessful();
}

function reminderDays(): array
{
    return PaymentReminderDelivery::query()->where('status', 'sent')->join('payment_reminder_rules', 'payment_reminder_rules.id', '=', 'rule_id')
        ->orderBy('payment_reminder_deliveries.id')->pluck('payment_reminder_rules.days')->map(fn ($days): int => (int) $days)->all();
}

it('manda cada escalón en su día y una sola vez', function () {
    remindOn('2027-02-27');
    expect(reminderDays())->toBe([]);

    remindOn('2027-02-28');
    remindOn('2027-02-28');
    expect(reminderDays())->toBe([-3]);

    remindOn('2027-03-03');
    remindOn('2027-03-05');
    remindOn('2027-03-10');
    remindOn('2027-04-02');
    expect(reminderDays())->toBe([-3, 0, 7, 30]);

    Mail::assertSent(SalesDocumentMail::class, 4);
    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('facturas@cliente.test') && $mail->hasSubject('Recordatorio: factura F270001 pendiente de pago'));
    expect(SalesDocumentEmail::query()->where('kind', 'reminder')->count())->toBe(4);
});

it('se pone al día si un día no corrió, pero no manda escalones viejos', function () {
    // El del día (03/03) se perdió; el 05/03 sale ese. El de -3 ya no.
    remindOn('2027-03-05');
    expect(reminderDays())->toBe([0]);

    // Más de 7 días tarde, el de 7 (10/03) ya no sale el 20/03.
    remindOn('2027-03-20');
    expect(reminderDays())->toBe([0]);
});

it('nunca a una cobrada ni a una anulada, ni con los recordatorios del cliente apagados', function () {
    $paid = invoicingIssue($this->admin, $this->client);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$paid->id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '726', 'method' => 'transfer']);
    $cancelled = invoicingIssue($this->admin, $this->client);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$cancelled->id}/anular", ['reason' => 'Factura duplicada']);
    $quiet = invoicingClient(['billing_emails' => ['otro@cliente.test'], 'payment_reminders' => false], ['name' => 'Sin recordatorios']);
    invoicingIssue($this->admin, $quiet);

    remindOn('2027-03-03');

    expect(PaymentReminderDelivery::query()->pluck('sales_document_id')->all())->toBe([$this->invoice->id]);
    Mail::assertNotSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasTo('otro@cliente.test'));
});

it('ni la serie de pruebas ni nada en modo de prueba (D-239)', function () {
    invoicingIssue($this->admin, $this->client, null, ['series_id' => invoicingSeries('PRU')->id]);
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);

    remindOn('2027-03-03');
    expect(PaymentReminderDelivery::query()->count())->toBe(0);
    Mail::assertNothingSent();

    enableInvoicing();
    remindOn('2027-03-03');
    expect(PaymentReminderDelivery::query()->pluck('sales_document_id')->all())->toBe([$this->invoice->id]);
});

it('sin destinatarios queda «no enviado» y sale en Requiere atención y en la vista de Facturas', function () {
    $nobody = invoicingClient([], ['name' => 'Sin emails']);
    $invoice = invoicingIssue($this->admin, $nobody);

    remindOn('2027-03-03');

    expect(PaymentReminderDelivery::query()->where('sales_document_id', $invoice->id)->value('status'))->toBe('failed');
    $summary = app(BillingSummary::class)->summary($this->admin, ['key' => 'anio', 'from' => '2027-01-01', 'to' => '2027-12-31']);
    expect($summary['attention']['reminders']['count'])->toBe(1)
        ->and(InvoiceList::fromQuery(['vista' => 'por-cobrar', 'recordatorio' => 'fallido'])->query()->get()->map(fn (BillingDocument $one): int => $one->id)->all())->toBe([-$invoice->id]);

    // Cobrada, deja de pedir atención.
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$invoice->id}/cobros", ['paid_on' => '2027-03-03', 'amount' => '726', 'method' => 'transfer']);
    $summary = app(BillingSummary::class)->summary($this->admin, ['key' => 'anio', 'from' => '2027-01-01', 'to' => '2027-12-31']);
    expect($summary['attention']['reminders']['count'])->toBe(0);
});

it('usa los destinatarios del último envío de la factura', function () {
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/envios", ['to' => ['pagos@cliente.test'], 'cc' => ['jefa@cliente.test'], 'subject' => 'Factura', 'body' => 'Hola']);

    remindOn('2027-03-03');

    Mail::assertSent(SalesDocumentMail::class, fn (SalesDocumentMail $mail): bool => $mail->hasSubject('Recordatorio: factura F270001 pendiente de pago') && $mail->hasTo('pagos@cliente.test') && $mail->hasCc('jefa@cliente.test'));
});

it('los escalones se cambian en Ajustes y un escalón quitado deja de salir', function () {
    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/recordatorios', ['days' => 15])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/recordatorios', ['days' => 15])->assertSessionHasErrors('days');
    $zero = PaymentReminderRule::query()->where('days', 0)->firstOrFail();
    $this->actingAs($this->admin)->delete("/facturacion/ajustes/emision/recordatorios/{$zero->id}")->assertRedirect();
    $seven = PaymentReminderRule::query()->where('days', 7)->firstOrFail();
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/recordatorios/{$seven->id}", ['days' => 7, 'is_active' => false])->assertRedirect();

    remindOn('2027-03-03');
    remindOn('2027-03-10');
    remindOn('2027-03-18');
    // Sin el del día, el 03/03 aún vale el de 3 días antes (se pone al día); el de 7, apagado.
    expect(reminderDays())->toBe([-3, 15]);
});
