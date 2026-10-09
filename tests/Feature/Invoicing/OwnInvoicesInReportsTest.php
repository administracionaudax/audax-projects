<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\InvoiceList;
use App\Domain\Billing\InvoicingQuery;
use App\Domain\Billing\InvoicingReport;
use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Billing\UnbilledReport;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\BillingDocument;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Las facturas propias cuentan igual que las de Holded (PLAN-EMISION §4.6 y §9; D-427): el listado
| unificado, Ventas, el Resumen, «Vendido frente a real» y Por facturar leen la vista
| billing_documents. La serie de pruebas no cuenta en ningún sitio (V-17).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-20 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    $this->fee = Project::factory()->monthlyFee(20, '1000.00')->create(['client_id' => $this->client->id, 'start_date' => '2027-01-01', 'due_date' => null]);

    // Holded: una factura de 2026 (ya emitida allí). Audax: enero y febrero del fee, una de prueba y un borrador.
    app()->instance(HoldedApi::class, holdedFake([
        'contacts' => [holdedContact('c1', $this->client->name, 'B87654321')],
        'invoices' => [holdedInvoice('h1', 'F260100', 'c1', '2026-12-01', '500.00')],
    ]));
    syncHolded();

    $this->january = invoicingIssue($this->admin, $this->client, [invoicingLine('1', '1000', 'iva_21', '0', 'Fee de enero')], ['issue_date' => '2027-01-31', 'project_id' => $this->fee->id]);
    $this->february = invoicingIssue($this->admin, $this->client, [invoicingLine('1', '1000', 'iva_21', '0', 'Fee de febrero')], ['issue_date' => '2027-02-28', 'project_id' => $this->fee->id]);
    $this->test = invoicingIssue($this->admin, $this->client, [invoicingLine('1', '9999', 'iva_21', '0', 'Prueba')], ['series_id' => invoicingSeries('PRU')->id, 'project_id' => $this->fee->id]);
    $this->draft = invoicingDraft($this->admin, $this->client, [invoicingLine('1', '1000', 'iva_21', '0', 'Fee de marzo')], ['project_id' => $this->fee->id]);
});

it('salen juntas en el listado, distinguidas por su origen, con la de prueba solo en «Pruebas»', function () {
    $numbers = fn (array $query = []): array => InvoiceList::fromQuery($query)->query()->get()->map(fn (BillingDocument $invoice): string => $invoice->number ?? 'borrador')->all();

    expect($numbers(['periodo' => 'todo']))->toBe(['F270002', 'F270001', 'F260100'])
        ->and($numbers(['vista' => 'borradores']))->toBe(['borrador'])
        ->and($numbers(['vista' => 'pruebas']))->toBe(['PRU270001'])
        ->and(InvoiceList::fromQuery(['periodo' => 'todo'])->viewCounts())->toMatchArray(['todas' => 3, 'por-cobrar' => 3, 'borradores' => 1, 'pruebas' => 1]);

    $this->actingAs($this->admin)->get('/facturacion/facturas?periodo=todo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.number', 'F270002')
            ->where('invoices.data.0.source', 'audax')
            ->where('invoices.data.0.id', -$this->february->id)
            ->where('invoices.data.0.url', "/facturacion/documentos/{$this->february->id}")
            ->where('invoices.data.0.links.0.project.code', $this->fee->code)
            ->where('invoices.data.2.source', 'holded')
            ->where('totals.subtotal', '2500.00'));
});

it('cuentan en Ventas y en el Resumen como las de Holded; el borrador es previsto y la de prueba no cuenta', function () {
    $report = app(InvoicingReport::class)->report(InvoicingQuery::fromQuery(['periodo' => 'anio']));

    expect($report['kpis'])->toMatchArray(['invoiced' => '2000.00', 'planned' => '1000.00', 'outstanding' => '2420.00']);

    $summary = app(BillingSummary::class)->summary($this->admin, BillingSummary::period([]));
    expect($summary['kpis']['outstanding'])->toBe('3025.00');

    // Anular la de febrero: ni ella ni su rectificativa cuentan (D-397).
    app(InvoiceCorrections::class)->cancel($this->february, $this->admin, 'Se factura con otro importe');
    expect(app(InvoicingReport::class)->report(InvoicingQuery::fromQuery(['periodo' => 'anio']))['kpis'])->toMatchArray(['invoiced' => '1000.00', 'outstanding' => '1210.00']);
});

it('cuentan en «Vendido frente a real» y en Por facturar del fee por sus enlaces', function () {
    $query = SoldVsActualQuery::fromQuery(['periodo' => 'rango', 'desde' => '2027-01-01', 'hasta' => '2027-03-31']);
    $unit = collect(app(SoldVsActual::class)->report($query, $this->admin, true)['units'])->firstWhere('project.id', $this->fee->id);

    expect($unit['invoiced'])->toBe('2000.00')
        ->and($unit['planned'])->toBe('1000.00')
        ->and($unit['invoices_count'])->toBe(2);

    // Por facturar: enero y febrero ya tienen factura; marzo, no (el borrador no es factura emitida).
    $unbilled = app(UnbilledReport::class)->report(new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'anio'])), true);
    $row = collect($unbilled['clients'])->firstWhere('client.id', $this->client->id);
    expect($row['amount'])->toBe('1000.00')
        ->and($row['sources']['fees'])->toBe(1);
});
