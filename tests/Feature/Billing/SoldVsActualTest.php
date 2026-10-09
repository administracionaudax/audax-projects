<?php

use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
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

/*
| «Vendido frente a real» (Fase 12, F1; D-390): sus cálculos por unidad de venta (bolsa, precio
| cerrado, fee y horas), las facturas repartidas al céntimo, el margen con el coste de las horas,
| los filtros y lo que ve quien no tiene view-financials. El semáforo, con los casos compartidos de
| tests/fixtures/billing/sold-vs-actual-status.json (también en Vitest).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    $this->admin = userWithRole('admin');
    $this->owner = userWithRole('employee', ['name' => 'Marta Gestora']);
    $this->worker = userWithRole('employee');
    $this->client = Client::factory()->create(['name' => 'Acme']);
    $this->other = Client::factory()->create(['name' => 'Zeta']);

    $this->bankProject = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'ACM-BH', 'owner_user_id' => $this->owner->id]);
    $this->bank = HourBank::factory()->create(['project_id' => $this->bankProject->id, 'name' => 'Bolsa 10 h', 'total_minutes' => 600, 'price_amount' => '1000.00', 'start_date' => '2026-01-01']);
    $this->fixed = Project::factory()->fixedPrice()->create(['client_id' => $this->client->id, 'code' => 'ACM-WE1', 'budget_minutes' => 1200, 'fixed_price_amount' => '2000.00']);
    $this->fee = Project::factory()->monthlyFee(10, '500.00')->create(['client_id' => $this->other->id, 'code' => 'ZET-FE1', 'start_date' => '2026-01-01']);
    $this->hourly = Project::factory()->create(['client_id' => $this->other->id, 'code' => 'ZET-HOR', 'hourly_rate' => '60.00']);
    Project::factory()->internal()->create(['code' => 'INTERNO']);

    $entry = function (Project $project, int $minutes, TimeEntryStatus $status, string $date = '2026-02-10', bool $billable = true, ?HourBank $bank = null): void {
        $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank?->id]);
        TimeEntry::factory()->forTask($task)->minutes($minutes)->status($status)->on($date)
            ->create(['user_id' => $this->worker->id, 'is_billable' => $billable, 'hourly_cost_snapshot' => '30.00', 'hourly_rate_snapshot' => '60.00']);
    };

    // Bolsa: 9 h reales (8 aprobadas y 1 bloqueada) y 1:30 pendientes de aprobar → 90 %, en riesgo.
    $entry($this->bankProject, 480, TimeEntryStatus::Approved, bank: $this->bank);
    $entry($this->bankProject, 60, TimeEntryStatus::Locked, bank: $this->bank);
    $entry($this->bankProject, 60, TimeEntryStatus::Submitted, bank: $this->bank);
    $entry($this->bankProject, 30, TimeEntryStatus::Draft, bank: $this->bank);
    // Precio cerrado: 22 h de 20 → pasado. Se mide entero (también lo de 2025).
    $entry($this->fixed, 1200, TimeEntryStatus::Approved, '2025-12-15');
    $entry($this->fixed, 120, TimeEntryStatus::Approved);
    // Fee: 15 h en el trimestre (de 30 vendidas); lo de diciembre no cuenta.
    $entry($this->fee, 900, TimeEntryStatus::Approved);
    $entry($this->fee, 300, TimeEntryStatus::Approved, '2025-12-10');
    // Por horas: 2 h facturables (y una no facturable que no cuenta).
    $entry($this->hourly, 120, TimeEntryStatus::Approved);
    $entry($this->hourly, 60, TimeEntryStatus::Approved, billable: false);

    $invoice = function (string $number, string $date, string $subtotal, string $paid = '0.00', array $links = [], HoldedDocumentKind $kind = HoldedDocumentKind::Invoice, CollectionStatus $status = CollectionStatus::Unpaid): HoldedInvoice {
        $tax = bcmul($subtotal, '0.21', 2);
        $total = bcadd($subtotal, $tax, 2);
        $model = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => $kind, 'number' => $number, 'number_normalized' => $number, 'issued_on' => $date,
            'subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $total, 'paid_total' => $paid, 'pending_total' => bcsub($total, $paid, 2),
            'collection_status' => $status,
        ]);
        foreach ($links as [$project, $bank]) {
            HoldedInvoiceLink::query()->create(['holded_invoice_id' => $model->id, 'project_id' => $project->id, 'hour_bank_id' => $bank?->id, 'method' => InvoiceLinkMethod::Manual]);
        }

        return $model;
    };

    $invoice('F260001', '2026-01-02', '1000.00', '605.00', [[$this->bankProject, $this->bank]], status: CollectionStatus::Partial);
    $invoice('F250090', '2025-11-20', '1000.00', '1210.00', [[$this->fixed, null]], status: CollectionStatus::Paid);
    // Una factura repartida entre el precio cerrado y el fee: 1.000,01 € → 500,01 y 500,00.
    $invoice('F260002', '2026-02-01', '1000.01', '0.00', [[$this->fixed, null], [$this->fee, null]], status: CollectionStatus::Overdue);
    // Rectificativa del fee (−100) y una anulada que no cuenta.
    $invoice('R260001', '2026-02-15', '-100.00', '0.00', [[$this->fee, null]], HoldedDocumentKind::CreditNote, CollectionStatus::Paid);
    $invoice('F260003', '2026-02-20', '700.00', '0.00', [[$this->fee, null]], status: CollectionStatus::Cancelled);
    // Fuera del periodo: una factura del fee de 2025 no cuenta (el fee se mide en el periodo).
    $invoice('F250100', '2025-12-01', '500.00', '605.00', [[$this->fee, null]], status: CollectionStatus::Paid);
    $invoice('F260004', '2026-03-01', '100.00', '0.00', [[$this->hourly, null]]);

    $this->query = SoldVsActualQuery::fromQuery(['periodo' => 'rango', 'desde' => '2026-01-01', 'hasta' => '2026-03-31']);
    $this->unit = fn (array $report, string $key): array => collect($report['units'])->firstWhere('key', $key);
});

it('calcula vendido, real, pendiente, desviación y semáforo de cada unidad', function () {
    $report = app(SoldVsActual::class)->report($this->query, $this->admin, true);
    $unit = fn (string $key): array => ($this->unit)($report, $key);

    expect($report['units'])->toHaveCount(4)
        // Primero lo pasado y lo que está en riesgo.
        ->and(array_column($report['units'], 'status'))->toBe(['over', 'risk', 'unbilled', 'ok']);

    expect($unit('bank:'.$this->bank->id))->toMatchArray([
        'kind' => 'bolsa', 'sold_minutes' => 600, 'real_minutes' => 540, 'pending_minutes' => 90,
        'deviation_minutes' => -60, 'consumption_pct' => 90.0, 'status' => 'risk', 'whole' => true,
    ]);
    expect($unit('project:'.$this->fixed->id))->toMatchArray(['kind' => 'precio_cerrado', 'sold_minutes' => 1200, 'real_minutes' => 1320, 'deviation_minutes' => 120, 'consumption_pct' => 110.0, 'status' => 'over']);
    expect($unit('project:'.$this->fee->id))->toMatchArray(['kind' => 'fee', 'months' => 3, 'sold_minutes' => 1800, 'real_minutes' => 900, 'consumption_pct' => 50.0, 'status' => 'ok']);
    // Por horas no hay nada vendido (D-416): las 2 h sin facturar son lo pendiente de facturar.
    expect($unit('project:'.$this->hourly->id))->toMatchArray(['kind' => 'horas', 'sold_minutes' => null, 'real_minutes' => 120, 'consumption_pct' => null,
        'deviation_minutes' => null, 'status' => 'unbilled', 'unbilled_minutes' => 120]);

    expect($report['totals'])->toMatchArray([
        'units' => 4, 'sold_minutes' => 3600, 'real_of_sold_minutes' => 2760, 'deviation_minutes' => -840, 'real_minutes' => 2880, 'pending_minutes' => 90,
        'by_status' => ['over' => 1, 'risk' => 1, 'unbilled' => 1, 'ok' => 1, 'billed' => 0, 'none' => 0],
        'unbilled_minutes' => 120,
    ]);
});

it('calcula lo facturado, cobrado, pendiente, coste, margen y precio efectivo al céntimo', function () {
    $report = app(SoldVsActual::class)->report($this->query, $this->admin, true);
    $unit = fn (string $key): array => ($this->unit)($report, $key);

    // Bolsa: 1.000 € vendidos; 9 h a 30 €/h de coste = 270 €.
    expect($unit('bank:'.$this->bank->id))->toMatchArray([
        'sold_amount' => '1000.00', 'invoiced' => '1000.00', 'invoiced_total' => '1210.00', 'collected' => '605.00', 'outstanding' => '605.00',
        'to_invoice' => '0.00', 'cost' => '270.00', 'margin' => '730.00', 'margin_pct' => 73.0, 'effective_rate' => '111.11', 'invoices_count' => 1,
    ]);
    // Precio cerrado: entero (con la factura de 2025) y la mitad de la compartida (con el céntimo de más).
    expect($unit('project:'.$this->fixed->id))->toMatchArray(['invoiced' => '1500.01', 'collected' => '1210.00', 'cost' => '660.00', 'margin' => '1340.00', 'to_invoice' => '499.99', 'invoices_count' => 2]);
    // Fee: 3 meses × 500 €; facturado en el periodo: 500,00 de la compartida − 100 de la rectificativa (sin la anulada ni la de 2025).
    expect($unit('project:'.$this->fee->id))->toMatchArray(['sold_amount' => '1500.00', 'invoiced' => '400.00', 'overdue' => '605.00', 'to_invoice' => '1100.00', 'cost' => '450.00', 'margin' => '1050.00']);
    // Por horas: el valor de las horas a su tarifa (2 h × 60 €) frente a lo facturado.
    expect($unit('project:'.$this->hourly->id))->toMatchArray(['sold_amount' => null, 'hours_value' => '120.00', 'invoiced' => '100.00', 'to_invoice' => '20.00', 'cost' => '90.00', 'margin' => '30.00']);

    // La compartida se reparte sin perder el céntimo y los totales suman las filas.
    expect(bcadd($unit('project:'.$this->fixed->id)['invoiced'], $unit('project:'.$this->fee->id)['invoiced'], 2))->toBe('1900.01')
        ->and($report['totals']['invoiced'])->toBe('3000.01')
        ->and($report['totals']['income'])->toBe('4620.00')
        // El coste, de todas las horas reales (también las no facturables).
        ->and($report['totals']['cost'])->toBe('1470.00')
        ->and($report['totals']['margin'])->toBe('3150.00');
});

it('sin view-financials no lleva ni un importe', function () {
    $report = app(SoldVsActual::class)->report($this->query, $this->admin, false);

    foreach ($report['units'] as $unit) {
        expect($unit)->not->toHaveKeys(['sold_amount', 'invoiced', 'collected', 'cost', 'margin', 'effective_rate', 'hours_value']);
    }
    expect($report['totals'])->not->toHaveKeys(['invoiced', 'margin', 'income']);
});

it('filtra por tipo de venta, cliente y responsable', function () {
    $report = fn (array $extra): array => app(SoldVsActual::class)->report(SoldVsActualQuery::fromQuery(['periodo' => 'rango', 'desde' => '2026-01-01', 'hasta' => '2026-03-31', ...$extra]), $this->admin, true);

    expect(array_column($report(['venta' => ['fee']])['units'], 'key'))->toBe(['project:'.$this->fee->id])
        ->and(array_column($report(['venta' => ['bolsa', 'nada']])['units'], 'kind'))->toBe(['bolsa'])
        ->and($report(['cliente' => [$this->other->id]])['totals']['units'])->toBe(2)
        ->and(array_column($report(['responsable' => $this->owner->id])['units'], 'key'))->toBe(['bank:'.$this->bank->id]);
});

it('una bolsa o un precio cerrado sin actividad en el periodo no salen; un fee vivo sí', function () {
    $report = app(SoldVsActual::class)->report(SoldVsActualQuery::fromQuery(['periodo' => 'rango', 'desde' => '2024-01-01', 'hasta' => '2024-03-31']), $this->admin, true);

    expect($report['units'])->toBe([]);
});

it('un gestor de proyecto solo ve los suyos', function () {
    $report = app(SoldVsActual::class)->report($this->query, $this->owner, false);

    expect(array_column($report['units'], 'key'))->toBe(['bank:'.$this->bank->id]);
});

it('el semáforo sigue los casos compartidos con el navegador', function (?int $sold, int $real, ?float $pct, string $status) {
    $computed = $sold !== null && $sold > 0 ? round($real / $sold * 100, 1) : null;

    expect($computed)->toBe($pct === null ? null : (float) $pct)
        ->and(SoldVsActual::status($computed))->toBe($status);
})->with(function (): array {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/billing/sold-vs-actual-status.json'), true)['cases'];

    return array_map(fn (array $case): array => [$case['sold'], $case['real'], $case['pct'] === null ? null : (float) $case['pct'], $case['status']], $cases);
});

it('por horas, una factura parcial no da un porcentaje ni un «pasado»: el resto es pendiente de facturar (D-416)', function () {
    // Como la F260314 enlazada con LAM-INT (VFR-4): 1 h 30 min facturada de 2 h reales.
    $invoice = HoldedInvoice::query()->where('number', 'F260004')->firstOrFail();
    $invoice->lines()->create(['position' => 1, 'name' => 'Horas desarrollo', 'units' => '1.5', 'unit_price' => '66.67', 'discount_pct' => '0', 'subtotal' => '100.00', 'tax_rate' => '21']);

    $report = app(SoldVsActual::class)->report($this->query, $this->admin, true);
    $hourly = ($this->unit)($report, 'project:'.$this->hourly->id);

    expect($hourly)->toMatchArray(['invoiced_minutes' => 90, 'sold_minutes' => null, 'consumption_pct' => null, 'status' => 'unbilled', 'unbilled_minutes' => 30, 'to_invoice' => '20.00'])
        // No entra en la escala de lo vendido ni en los totales de consumo.
        ->and($report['totals']['sold_minutes'])->toBe(3600)
        ->and($report['totals']['by_status']['over'])->toBe(1);
});

it('el estado por horas sigue los casos compartidos con el navegador', function (int $invoiced, int $real, string $status, int $unbilled) {
    expect(SoldVsActual::hourlyStatus($invoiced, $real))->toBe($status)
        ->and(max(0, $real - $invoiced))->toBe($unbilled);
})->with(function (): array {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/billing/sold-vs-actual-status.json'), true)['hourly_cases'];

    return array_map(fn (array $case): array => [$case['invoiced'], $case['real'], $case['status'], $case['unbilled']], $cases);
});

it('cuenta los meses naturales de un periodo', function () {
    expect(SoldVsActual::months(CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-03-02')))->toBe(3)
        ->and(SoldVsActual::months(CarbonImmutable::parse('2025-12-01'), CarbonImmutable::parse('2026-01-31')))->toBe(2)
        ->and(SoldVsActual::months(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-03-31')))->toBe(0);
});
