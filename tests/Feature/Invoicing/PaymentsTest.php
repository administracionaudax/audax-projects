<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\InvoiceList;
use App\Domain\Billing\InvoicingQuery;
use App\Domain\Billing\InvoicingReport;
use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\PaymentRecorder;
use App\Domain\Billing\Issuing\PortalInvoices;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Models\BillingDocument;
use App\Models\Payment;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Cobros de las facturas propias (PLAN-EMISION §3.3, E2; D-436 y D-437): parciales y totales, con
| los estados pendiente, cobrada en parte, cobrada y vencida; anular un cobro con auditoría; la
| devolución en una rectificativa; y que el listado, Por cobrar, el Resumen y Ventas los cuenten
| igual que los de Holded.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    // 10 h × 60 € + IVA 21 % = 726,00 €, a 30 días (vence el 03/03/2027).
    $this->invoice = invoicingIssue($this->admin, $this->client);
});

function collectionOf(SalesDocument $document): string
{
    return BillingDocument::query()->whereKey(-$document->id)->firstOrFail()->collection_status->value;
}

function pay(SalesDocument $document, string $amount, string $day = '2027-02-01'): void
{
    test()->actingAs(test()->admin)->post("/facturacion/documentos/{$document->id}/cobros", [
        'paid_on' => $day, 'amount' => $amount, 'method' => 'transfer', 'bank_reference' => 'TRF-1',
    ])->assertSessionHasNoErrors()->assertRedirect();
}

it('pasa por pendiente, cobrada en parte y cobrada, con lo pendiente al céntimo', function () {
    expect((string) $this->invoice->total)->toBe('726.00')
        ->and(collectionOf($this->invoice))->toBe('unpaid');

    pay($this->invoice, '300.50');
    $this->invoice->refresh();
    expect((string) $this->invoice->paid_total)->toBe('300.50')
        ->and($this->invoice->pendingAmount())->toBe('425.50')
        ->and(collectionOf($this->invoice))->toBe('partial')
        ->and(BillingDocument::query()->whereKey(-$this->invoice->id)->firstOrFail()->pending_total)->toBe('425.50');

    pay($this->invoice, '425.50');
    $this->invoice->refresh();
    expect((string) $this->invoice->paid_total)->toBe('726.00')
        ->and($this->invoice->pendingAmount())->toBe('0.00')
        ->and(collectionOf($this->invoice))->toBe('paid');

    // La ficha lo enseña: los dos cobros, nada pendiente y ya no ofrece registrar otro (lo dice el servidor).
    $this->get("/facturacion/documentos/{$this->invoice->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.collection', 'paid')
            ->where('document.remaining', '0.00')
            ->has('document.payments', 2)
            ->where('document.payments.0.amount', '300.50')
            ->where('document.payments.0.method', 'transfer'));
});

it('con el vencimiento pasado y algo pendiente, vencida; la vista Vencidas y Por cobrar la cuentan', function () {
    pay($this->invoice, '100');
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00', 'Europe/Madrid'));

    // La vista calcula «vencida» con la fecha de la base de datos; la ficha y el portal, con la de Madrid.
    expect(PortalInvoices::collection($this->invoice->refresh()))->toBe('overdue');
    $list = InvoiceList::fromQuery(['vista' => 'vencidas']);
    expect($list->viewCounts())->toMatchArray(['por-cobrar' => 1, 'vencidas' => 1])
        ->and($list->collectionBar()['vencido'])->toBe(['amount' => '626.00', 'count' => 1])
        ->and($list->collectionBar()['cobrado'])->toBe(['amount' => '100.00', 'count' => 1]);
});

it('no deja cobrar más de lo pendiente, con fecha futura ni anterior a la factura, ni una factura anulada', function () {
    $recorder = app(PaymentRecorder::class);
    $data = fn (string $amount, string $day = '2027-02-01'): array => ['paid_on' => $day, 'amount' => $amount, 'method' => 'transfer'];

    expect(fn () => $recorder->record($this->invoice, $data('726.01'), $this->admin))->toThrow(ValidationException::class, 'no puede pasar de lo pendiente (726,00 €)')
        ->and(fn () => $recorder->record($this->invoice, $data('10', '2027-02-02'), $this->admin))->toThrow(ValidationException::class, 'no puede ser futura')
        ->and(fn () => $recorder->record($this->invoice, $data('10', '2027-01-15'), $this->admin))->toThrow(ValidationException::class, 'anterior a la factura')
        ->and(fn () => $recorder->record($this->invoice, $data('0'), $this->admin))->toThrow(ValidationException::class);

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros", $data('900'))->assertSessionHasErrors('amount');

    app(InvoiceCorrections::class)->cancel($this->invoice, $this->admin, 'Factura duplicada');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros", $data('10'))->assertForbidden();
    expect(Payment::query()->count())->toBe(0);
});

it('anula un cobro con motivo: vuelve lo pendiente, queda en la historia y en la auditoría', function () {
    pay($this->invoice, '726');
    $payment = Payment::query()->firstOrFail();

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros/{$payment->id}/anular", ['reason' => 'abc'])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros/{$payment->id}/anular", ['reason' => 'Transferencia devuelta por el banco'])->assertRedirect();

    $payment->refresh();
    expect($payment->voided_at)->not->toBeNull()
        ->and($payment->void_reason)->toBe('Transferencia devuelta por el banco')
        ->and((string) $this->invoice->refresh()->paid_total)->toBe('0.00')
        ->and(collectionOf($this->invoice))->toBe('unpaid')
        ->and(Activity::query()->where('event', 'payment_voided')->where('subject_id', $this->invoice->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'payment_recorded')->exists())->toBeTrue();

    // Un cobro anulado no se vuelve a anular; el de otra factura, 404.
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->invoice->id}/cobros/{$payment->id}/anular", ['reason' => 'Otra vez el banco'])->assertSessionHasErrors('reason');
    $other = invoicingIssue($this->admin, $this->client);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$other->id}/cobros/{$payment->id}/anular", ['reason' => 'No es suya'])->assertNotFound();

    // El cobro sigue en la ficha, anulado.
    $this->get("/facturacion/documentos/{$this->invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->where('document.payments.0.voided.reason', 'Transferencia devuelta por el banco'));
});

it('registra la devolución en una rectificativa (en negativo) hasta su total', function () {
    pay($this->invoice, '726');
    $credit = app(InvoiceCorrections::class)->cancel($this->invoice->refresh(), $this->admin, 'Factura duplicada');

    expect(PaymentRecorder::remaining($credit))->toBe('726.00');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$credit->id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '726', 'method' => 'transfer'])->assertRedirect();

    expect((string) $credit->refresh()->paid_total)->toBe('-726.00')
        ->and(PaymentRecorder::remaining($credit))->toBe('0.00')
        ->and((string) Payment::query()->where('sales_document_id', $credit->id)->value('amount'))->toBe('-726.00');
});

it('dos cobros a la vez no se pasan de lo pendiente (la factura se bloquea)', function () {
    $recorder = app(PaymentRecorder::class);
    $recorder->record($this->invoice, ['paid_on' => '2027-02-01', 'amount' => '700', 'method' => 'transfer'], $this->admin);

    // Con el paid_total de antes en memoria, el segundo lee la factura bloqueada y ve lo de verdad.
    $stale = SalesDocument::query()->findOrFail($this->invoice->id);
    $stale->setRawAttributes([...$stale->getAttributes(), 'paid_total' => '0.00']);
    expect(fn () => $recorder->record($stale, ['paid_on' => '2027-02-01', 'amount' => '100', 'method' => 'cash'], $this->admin))->toThrow(ValidationException::class);
    expect(DB::table('payments')->count())->toBe(1);
});

it('el Resumen y Ventas cuentan los cobros propios como los de Holded (por fecha de cobro, sin la serie de pruebas)', function () {
    pay($this->invoice, '200', '2027-02-01');
    $test = invoicingIssue($this->admin, $this->client, null, ['series_id' => invoicingSeries('PRU')->id]);
    pay($test, '50');

    $period = ['key' => 'anio', 'from' => '2027-01-01', 'to' => '2027-12-31'];
    $summary = app(BillingSummary::class)->summary($this->admin, $period);
    $february = collect($summary['months'])->firstWhere('month', '2027-02');

    expect($february['collected'])->toBe('200.00')
        ->and($summary['kpis']['outstanding'])->toBe('526.00');

    $query = new InvoicingQuery(new ReportFilters(ReportPeriod::Year, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31')), [], CarbonImmutable::parse('2027-02-01'));
    expect(app(InvoicingReport::class)->report($query, CarbonImmutable::parse('2027-02-01'))['kpis']['collected'])->toBe('200.00');

    // Anulado, deja de contar.
    app(PaymentRecorder::class)->void(Payment::query()->where('sales_document_id', $this->invoice->id)->firstOrFail(), 'Devuelto por el banco', $this->admin);
    $summary = app(BillingSummary::class)->summary($this->admin, $period);
    expect(collect($summary['months'])->firstWhere('month', '2027-02')['collected'])->toBe('0.00');
});

it('como en Holded, el cobro de una factura anulada no cuenta en el Resumen, Ventas ni lo cobrado del listado (D-449)', function () {
    pay($this->invoice, '300', '2027-02-01');
    $other = invoicingIssue($this->admin, $this->client);
    pay($other, '100', '2027-02-01');

    // Anulada (rectificativa por el total) después de cobrarla en parte: su cobro ya no cuenta, ni
    // la devolución de su rectificativa.
    $credit = app(InvoiceCorrections::class)->cancel($this->invoice->refresh(), $this->admin, 'Factura duplicada');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$credit->id}/cobros", ['paid_on' => '2027-02-01', 'amount' => '300', 'method' => 'transfer'])->assertRedirect();

    $period = ['key' => 'anio', 'from' => '2027-01-01', 'to' => '2027-12-31'];
    $summary = app(BillingSummary::class)->summary($this->admin, $period);
    expect(collect($summary['months'])->firstWhere('month', '2027-02')['collected'])->toBe('100.00')
        ->and($summary['kpis']['outstanding'])->toBe('626.00');

    $query = new InvoicingQuery(new ReportFilters(ReportPeriod::Year, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31')), [], CarbonImmutable::parse('2027-02-01'));
    expect(app(InvoicingReport::class)->report($query, CarbonImmutable::parse('2027-02-01'))['kpis']['collected'])->toBe('100.00');

    $bar = InvoiceList::fromQuery(['periodo' => 'todo'], CarbonImmutable::parse('2027-02-01'))->collectionBar();
    expect($bar['cobrado'])->toBe(['amount' => '100.00', 'count' => 1])
        ->and($bar['vencido']['amount'])->toBe('0.00');

    // La ficha de la anulada sigue enseñando su cobro (no se borra nada).
    $this->get("/facturacion/documentos/{$this->invoice->id}")->assertInertia(fn (Assert $page) => $page->has('document.payments', 1));
});
