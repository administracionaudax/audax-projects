<?php

use App\Domain\Billing\UnbilledReport;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Por facturar» por cliente (I10, D-412): horas aprobadas sin facturar de los proyectos por horas,
| excesos de bolsa, bolsas vendidas sin factura y meses de fee sin factura, con su importe solo con
| view-billing, y la lista como portada de /facturacion/por-facturar (el detalle de un cliente sigue
| con ?cliente[]=).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->worker = userWithRole('employee');

    $this->acme = Client::factory()->create(['name' => 'Acme']);
    $this->zeta = Client::factory()->create(['name' => 'Zeta']);
    $this->quiet = Client::factory()->create(['name' => 'Al día']);

    // Acme: por horas a 60 €/h, 10 h aprobadas (y 1 h enviada), 4 h facturadas en una factura de 240 €.
    $this->hourly = Project::factory()->create(['client_id' => $this->acme->id, 'code' => 'ACM-HOR', 'hourly_rate' => '60.00']);
    // Zeta: una bolsa de 10 h con 2 h de exceso a 50 €/h y otra bolsa vendida en marzo sin factura.
    $this->bankProject = Project::factory()->hourBank()->create(['client_id' => $this->zeta->id, 'code' => 'ZET-BH', 'hourly_rate' => '50.00']);
    $this->bank = HourBank::factory()->create(['project_id' => $this->bankProject->id, 'total_minutes' => 600, 'price_amount' => '500.00', 'start_date' => '2025-12-01']);
    $this->newBank = HourBank::factory()->create(['project_id' => $this->bankProject->id, 'total_minutes' => 600, 'price_amount' => '550.00', 'start_date' => '2026-03-01']);
    // Zeta: un fee de 300 €/mes desde enero con factura solo en enero → febrero y marzo sin factura.
    $this->fee = Project::factory()->monthlyFee(600, '300.00')->create(['client_id' => $this->zeta->id, 'code' => 'ZET-FE1', 'start_date' => '2026-01-01']);
    // Al día: por horas, todo facturado.
    $this->quietProject = Project::factory()->create(['client_id' => $this->quiet->id, 'code' => 'DIA-HOR', 'hourly_rate' => '60.00']);

    $entry = function (Project $project, int $minutes, TimeEntryStatus $status, string $date, int $overage = 0, ?HourBank $bank = null): void {
        $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank?->id]);
        TimeEntry::factory()->forTask($task)->minutes($minutes)->status($status)->on($date)
            ->create(['user_id' => $this->worker->id, 'is_billable' => true, 'overage_minutes' => $overage, 'hourly_rate_snapshot' => '60.00', 'hourly_cost_snapshot' => '30.00']);
    };
    $entry($this->hourly, 600, TimeEntryStatus::Approved, '2026-02-03');
    $entry($this->hourly, 60, TimeEntryStatus::Submitted, '2026-03-02');
    $entry($this->bankProject, 720, TimeEntryStatus::Approved, '2026-02-10', overage: 120, bank: $this->bank);
    $entry($this->quietProject, 120, TimeEntryStatus::Approved, '2026-02-12');

    $invoice = function (string $number, string $date, string $subtotal, array $lines, array $links): void {
        $model = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'issued_on' => $date,
            'subtotal' => $subtotal, 'tax_total' => '0.00', 'total' => $subtotal, 'paid_total' => '0.00', 'pending_total' => $subtotal,
            'collection_status' => CollectionStatus::Unpaid,
        ]);
        foreach ($lines as $n => [$name, $units, $amount]) {
            $model->lines()->create(['position' => $n + 1, 'name' => $name, 'units' => $units, 'unit_price' => '60.00', 'discount_pct' => '0', 'subtotal' => $amount, 'tax_rate' => '0']);
        }
        foreach ($links as [$project, $bank]) {
            HoldedInvoiceLink::query()->create(['holded_invoice_id' => $model->id, 'project_id' => $project->id, 'hour_bank_id' => $bank?->id, 'method' => InvoiceLinkMethod::Manual]);
        }
    };
    $invoice('F260010', '2026-02-28', '240.00', [['Desarrollo', '4', '240.00']], [[$this->hourly, null]]);
    $invoice('F260011', '2026-01-05', '300.00', [['Fee mensual', '1', '300.00']], [[$this->fee, null]]);
    $invoice('F260012', '2026-02-28', '120.00', [['Desarrollo', '2', '120.00']], [[$this->quietProject, null]]);
    $invoice('F250200', '2025-12-01', '500.00', [['bolsadehoras', '10', '500.00']], [[$this->bankProject, $this->bank]]);

    $this->report = fn (bool $financials = true, array $query = ['periodo' => 'anio']): array => app(UnbilledReport::class)
        ->report(new ReportScope($this->admin, ReportFilters::fromQuery($query)), $financials);
});

it('suma por cliente las horas sin facturar, los excesos, las bolsas sin factura y los fees sin factura', function () {
    $report = ($this->report)();
    $row = fn (Client $client): ?array => collect($report['clients'])->firstWhere('client.id', $client->id);

    // Acme: 10 h − 4 h facturadas = 6 h; 600 € de valor − 240 € = 360 €. La hora enviada, aparte.
    expect($row($this->acme))->toMatchArray([
        'minutes' => 360, 'pending_minutes' => 60, 'amount' => '360.00', 'oldest' => '2026-02-03',
        'sources' => ['hours' => 1, 'overage' => 0, 'banks' => 0, 'fees' => 0, 'fixed' => 0],
    ]);
    // Zeta: 2 h de exceso a 50 € (100 €) + la bolsa de marzo (550 €) + febrero y marzo del fee (600 €).
    expect($row($this->zeta))->toMatchArray([
        'minutes' => 120, 'amount' => '1250.00', 'oldest' => '2026-02-01',
        'sources' => ['hours' => 0, 'overage' => 1, 'banks' => 1, 'fees' => 2, 'fixed' => 0],
    ]);
    // Quien lo tiene todo facturado no sale.
    expect($row($this->quiet))->toBeNull()
        // Primero quien más tiene por facturar.
        ->and(array_column(array_column($report['clients'], 'client'), 'name'))->toBe(['Zeta', 'Acme'])
        ->and($report['totals'])->toBe(['clients' => 2, 'minutes' => 480, 'pending_minutes' => 60, 'amount' => '1610.00']);
});

it('el exceso de una bolsa sin bolsa siguiente sigue pendiente aunque haya facturado de más (D-433) y los meses futuros de un fee no cuentan', function () {
    $extra = HoldedInvoice::query()->create([
        'holded_id' => 'h-x', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260020', 'issued_on' => '2026-03-01',
        'subtotal' => '100.00', 'tax_total' => '0.00', 'total' => '100.00', 'paid_total' => '0.00', 'pending_total' => '100.00', 'collection_status' => CollectionStatus::Unpaid,
    ]);
    $extra->lines()->create(['position' => 1, 'name' => 'bolsadehoras', 'units' => '2', 'unit_price' => '50', 'discount_pct' => '0', 'subtotal' => '100.00', 'tax_rate' => '0']);
    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $extra->id, 'project_id' => $this->bankProject->id, 'hour_bank_id' => $this->bank->id, 'method' => InvoiceLinkMethod::Manual]);

    $zeta = collect(($this->report)()['clients'])->firstWhere('client.id', $this->zeta->id);

    // Ya no se descuenta lo facturado de más (cambia D-412); abril a diciembre del fee aún no han empezado.
    expect($zeta)->toMatchArray(['minutes' => 120, 'amount' => '1250.00', 'sources' => ['hours' => 0, 'overage' => 1, 'banks' => 1, 'fees' => 2, 'fixed' => 0]]);
});

it('sin view-billing solo horas: sin importes, sin bolsas ni fees y sin descontar lo facturado en Holded', function () {
    $report = ($this->report)(false);

    expect($report['financials'])->toBeFalse()
        ->and($report['totals']['amount'])->toBeNull()
        ->and(collect($report['clients'])->pluck('amount')->filter()->all())->toBe([])
        ->and(collect($report['clients'])->firstWhere('client.id', $this->acme->id)['minutes'])->toBe(600)
        ->and(collect($report['clients'])->firstWhere('client.id', $this->zeta->id)['sources'])->toBe(['hours' => 0, 'overage' => 1, 'banks' => 0, 'fees' => 0, 'fixed' => 0])
        ->and(collect($report['clients'])->firstWhere('client.id', $this->quiet->id)['minutes'])->toBe(120);
});

it('/facturacion/por-facturar abre con la lista (el año en curso) y ?cliente[]= abre el detalle de siempre', function () {
    $this->actingAs($this->admin)->get('/facturacion/por-facturar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/unbilled')
            ->where('filters.period', 'anio')
            ->where('report.financials', true)
            ->where('report.totals.clients', 2)
            ->where('report.clients.0.client.name', 'Zeta'));

    $this->actingAs($this->admin)->get('/facturacion/por-facturar?periodo=mes')
        ->assertInertia(fn (Assert $page) => $page->component('billing/unbilled')->where('filters.period', 'mes'));

    $this->actingAs($this->admin)->get("/facturacion/por-facturar?cliente[]={$this->acme->id}&periodo=anio")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/hours')->where('client.id', $this->acme->id));
});

it('con el módulo apagado (D-402) la lista sigue, en horas', function () {
    enableBilling(false);

    $this->actingAs($this->admin)->get('/facturacion/por-facturar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/unbilled')
            ->where('report.financials', false)
            ->where('report.totals.amount', null));
});
