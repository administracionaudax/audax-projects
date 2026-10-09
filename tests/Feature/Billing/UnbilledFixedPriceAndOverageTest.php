<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\UnbilledReport;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\HourBankStatus;
use App\Enums\InvoiceLinkMethod;
use App\Enums\Permission;
use App\Enums\ProjectStatus;
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
| «Por facturar» con las respuestas del propietario del 09/10 (D-432 y D-433):
| - los precios cerrados: el precio menos lo facturado y enlazado (emitidas − rectificativas, sin
|   borradores), de los proyectos activos o acabados hace poco, con las horas y el % consumido como
|   contexto; solo con view-billing;
| - el exceso de bolsa se factura con la bolsa siguiente: el de una bolsa renovada ya no es pendiente
|   («Pasado a la bolsa siguiente»); el de la bolsa sin sucesora, sí.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->worker = userWithRole('employee');

    $this->obra = Client::factory()->create(['name' => 'Obra Nueva']);
    $this->bolsas = Client::factory()->create(['name' => 'Bolsas y Más']);

    $this->entry = function (Project $project, int $minutes, string $date, int $overage = 0, ?HourBank $bank = null, TimeEntryStatus $status = TimeEntryStatus::Approved): void {
        $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank?->id]);
        TimeEntry::factory()->forTask($task)->minutes($minutes)->status($status)->on($date)
            ->create(['user_id' => $this->worker->id, 'is_billable' => true, 'overage_minutes' => $overage, 'hourly_rate_snapshot' => '50.00', 'hourly_cost_snapshot' => '25.00']);
    };
    $this->invoice = function (string $number, string $subtotal, Project $project, array $extra = []): HoldedInvoice {
        $invoice = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'client_id' => $project->client_id, 'issued_on' => '2026-02-01',
            'subtotal' => $subtotal, 'tax_total' => '0.00', 'total' => $subtotal, 'paid_total' => '0.00', 'pending_total' => $subtotal,
            'collection_status' => CollectionStatus::Unpaid, ...$extra,
        ]);
        HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $project->id, 'method' => InvoiceLinkMethod::Manual]);

        return $invoice;
    };
    $this->fixed = fn (string $code, string $price, array $extra = []): Project => Project::factory()->fixedPrice()
        ->create(['client_id' => $this->obra->id, 'code' => $code, 'fixed_price_amount' => $price, 'start_date' => '2026-01-01', ...$extra]);

    $this->report = fn (bool $financials = true, array $query = ['periodo' => 'anio']): array => app(UnbilledReport::class)
        ->report(new ReportScope($this->admin, ReportFilters::fromQuery($query)), $financials);
    $this->detail = fn (Client $client, bool $financials = true, array $query = ['periodo' => 'anio']): array => app(UnbilledReport::class)
        ->detail(new ReportScope($this->admin, ReportFilters::fromQuery([...$query, 'cliente' => [$client->id]])), $financials, $client->id);
});

it('un precio cerrado activo: el precio menos lo facturado (emitidas − rectificativas, sin borradores), con las horas y el % consumido', function () {
    $project = ($this->fixed)('OBR-WEB', '10000.00', ['budget_minutes' => 6000]);
    ($this->entry)($project, 3600, '2026-02-10');
    ($this->entry)($project, 600, '2026-03-02', status: TimeEntryStatus::Submitted);
    $first = ($this->invoice)('F260030', '6000.00', $project);
    ($this->invoice)('CN260002', '-1000.00', $project, ['kind' => HoldedDocumentKind::CreditNote, 'rectified_invoice_id' => $first->id]);
    ($this->invoice)('BORRADOR', '2000.00', $project, ['number' => null, 'is_draft' => true, 'collection_status' => CollectionStatus::Draft]);
    // Una factura anulada no cuenta.
    ($this->invoice)('F260031', '999.00', $project, ['collection_status' => CollectionStatus::Cancelled]);

    $row = collect(($this->report)()['clients'])->firstWhere('client.id', $this->obra->id);
    expect($row)->toMatchArray([
        'minutes' => 0, 'pending_minutes' => 0, 'amount' => '5000.00', 'oldest' => '2026-01-01',
        'sources' => ['hours' => 0, 'overage' => 0, 'banks' => 0, 'fees' => 0, 'fixed' => 1],
    ]);

    expect(($this->detail)($this->obra))->toBe([
        'lines' => [[
            'source' => 'fixed',
            'project' => ['id' => $project->id, 'code' => 'OBR-WEB', 'name' => $project->name],
            'bank' => null, 'minutes' => 0, 'pending_minutes' => 0, 'amount' => '5000.00', 'oldest' => '2026-01-01', 'months' => null, 'next_bank' => null,
            'fixed' => ['price' => '10000.00', 'invoiced' => '5000.00', 'real_minutes' => 3600, 'budget_minutes' => 6000, 'consumption_pct' => 60.0],
        ]],
        'totals' => ['minutes' => 0, 'pending_minutes' => 0, 'amount' => '5000.00'],
        'financials' => true,
    ]);
});

it('entran los activos, en pausa y acabados hace poco con algo pendiente; no los acabados hace tiempo, archivados, previstos ni facturados del todo', function () {
    ($this->fixed)('OBR-ACT', '1000.00');
    ($this->fixed)('OBR-PAU', '800.00', ['status' => ProjectStatus::OnHold]);
    // Acabado con su fecha de fin hace menos de 90 días (hoy, 20/03; desde el 20/12/2025).
    ($this->fixed)('OBR-FIN', '600.00', ['status' => ProjectStatus::Completed, 'due_date' => '2026-01-31']);
    // Acabado con la fecha de fin vieja pero horas aprobadas hace poco.
    $late = ($this->fixed)('OBR-TAR', '400.00', ['status' => ProjectStatus::Completed, 'due_date' => '2025-06-30']);
    ($this->entry)($late, 60, '2026-02-15');
    // Fuera: acabado hace tiempo, archivado, previsto, facturado del todo y uno que empieza después.
    ($this->fixed)('OBR-OLD', '5000.00', ['status' => ProjectStatus::Completed, 'due_date' => '2025-06-30']);
    ($this->fixed)('OBR-ARC', '5000.00', ['status' => ProjectStatus::Archived]);
    ($this->fixed)('OBR-PRE', '5000.00', ['status' => ProjectStatus::Planned]);
    $paid = ($this->fixed)('OBR-PAG', '3000.00');
    ($this->invoice)('F260040', '3000.00', $paid);
    ($this->fixed)('OBR-FUT', '5000.00', ['start_date' => '2027-01-01']);

    $codes = collect(($this->detail)($this->obra)['lines'])->pluck('project.code')->all();
    expect($codes)->toBe(['OBR-ACT', 'OBR-FIN', 'OBR-PAU', 'OBR-TAR'])
        ->and(collect(($this->report)()['clients'])->firstWhere('client.id', $this->obra->id)['amount'])->toBe('2800.00');
});

it('sin view-billing no hay precios cerrados (ni ningún importe)', function () {
    ($this->fixed)('OBR-WEB', '10000.00');

    expect(($this->report)(false)['clients'])->toBe([])
        ->and(($this->detail)($this->obra, false))->toBe(['lines' => [], 'totals' => ['minutes' => 0, 'pending_minutes' => 0, 'amount' => null], 'financials' => false]);
});

it('el exceso de una bolsa renovada pasa a la siguiente; el de la bolsa sin sucesora es pendiente (D-433)', function () {
    $project = Project::factory()->hourBank()->create(['client_id' => $this->bolsas->id, 'code' => 'BOL-BH', 'hourly_rate' => '50.00']);
    $old = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'Bolsa enero', 'total_minutes' => 600, 'price_amount' => '500.00', 'start_date' => '2025-12-01', 'status' => HourBankStatus::Renewed]);
    $current = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'Bolsa febrero', 'total_minutes' => 600, 'price_amount' => '500.00', 'start_date' => '2025-12-15', 'renewed_from_id' => $old->id]);
    ($this->entry)($project, 720, '2026-01-20', overage: 120, bank: $old);
    ($this->entry)($project, 660, '2026-03-10', overage: 60, bank: $current);
    // Las dos bolsas tienen su factura (no salen como «bolsa sin factura»).
    foreach ([$old, $current] as $n => $bank) {
        $invoice = ($this->invoice)('F26006'.$n, '500.00', $project);
        HoldedInvoiceLink::query()->where('holded_invoice_id', $invoice->id)->update(['hour_bank_id' => $bank->id]);
    }

    $row = collect(($this->report)()['clients'])->firstWhere('client.id', $this->bolsas->id);
    // Solo la hora de la bolsa activa, a 50 €/h.
    expect($row)->toMatchArray(['minutes' => 60, 'amount' => '50.00', 'oldest' => '2026-03-10', 'sources' => ['hours' => 0, 'overage' => 1, 'banks' => 0, 'fees' => 0, 'fixed' => 0]]);

    $detail = ($this->detail)($this->bolsas);
    expect($detail['totals'])->toBe(['minutes' => 60, 'pending_minutes' => 0, 'amount' => '50.00'])
        ->and(collect($detail['lines'])->map(fn (array $line): array => [$line['source'], $line['bank']['name'], $line['minutes'], $line['amount'], $line['next_bank']['name'] ?? null])->all())
        ->toBe([
            ['overage', 'Bolsa febrero', 60, '50.00', null],
            ['carried', 'Bolsa enero', 120, null, 'Bolsa febrero'],
        ]);

    // Sin importes: el mismo reparto en horas.
    $hours = ($this->detail)($this->bolsas, false);
    expect($hours['totals'])->toBe(['minutes' => 60, 'pending_minutes' => 0, 'amount' => null])
        ->and(collect($hours['lines'])->pluck('amount')->all())->toBe([null, null]);

    // Si solo queda el exceso traspasado, el cliente no sale en la lista (pero sí en su detalle).
    TimeEntry::query()->where('hour_bank_id', $current->id)->delete();
    expect(collect(($this->report)()['clients'])->firstWhere('client.id', $this->bolsas->id))->toBeNull()
        ->and(collect(($this->detail)($this->bolsas)['lines'])->pluck('source')->all())->toBe(['carried']);
});

it('el Resumen y el detalle cuentan lo mismo que la lista', function () {
    ($this->fixed)('OBR-WEB', '1200.00');
    $project = Project::factory()->hourBank()->create(['client_id' => $this->bolsas->id, 'hourly_rate' => '50.00']);
    $old = HourBank::factory()->create(['project_id' => $project->id, 'total_minutes' => 600, 'start_date' => '2025-12-01']);
    HourBank::factory()->create(['project_id' => $project->id, 'total_minutes' => 600, 'start_date' => '2026-01-01', 'renewed_from_id' => $old->id]);
    ($this->entry)($project, 720, '2026-01-20', overage: 120, bank: $old);

    $summary = app(BillingSummary::class)->summary($this->admin, BillingSummary::period([]));
    expect($summary['kpis']['unbilled'])->toBe('1200.00')
        ->and($summary['kpis']['unbilled_clients'])->toBe(1);
});

it('el detalle del cliente en Por facturar lleva sus líneas; los importes, solo con view-billing', function () {
    $project = ($this->fixed)('OBR-WEB', '10000.00', ['budget_minutes' => 6000]);
    ($this->entry)($project, 3000, '2026-03-02');

    $this->actingAs($this->admin)->get("/facturacion/por-facturar?cliente[]={$this->obra->id}&periodo=anio")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/hours')
            ->where('unbilled.financials', true)
            ->where('unbilled.totals.amount', '10000.00')
            ->where('unbilled.lines.0.source', 'fixed')
            ->where('unbilled.lines.0.fixed.consumption_pct', 50)
            ->where('unbilled.lines.0.fixed.real_minutes', 3000));

    // Con el módulo apagado (D-402), quien ve Por facturar no ve importes ni precios cerrados.
    $finance = userWithRole('employee');
    $finance->givePermissionTo(Permission::ViewFinancials->value);
    enableBilling(false);
    $this->actingAs($finance)->get("/facturacion/por-facturar?cliente[]={$this->obra->id}&periodo=anio")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('unbilled.financials', false)
            ->where('unbilled.totals.amount', null)
            ->where('unbilled.lines', []));
});
