<?php

use App\Domain\Billing\BillingSummary;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\HourBankStatus;
use App\Enums\InvoiceLinkMethod;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedPayment;
use App\Models\HourBank;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| La portada «Resumen» de Facturación (I1, D-411): lo que requiere atención, las cuatro cifras (sin
| IVA y con IVA), lo facturado y cobrado por mes, la antigüedad de lo pendiente con los clientes que
| más deben y lo que queda por facturar, calculado a mano.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->acme = Client::factory()->create(['name' => 'Acme']);
    $this->project = Project::factory()->create(['client_id' => $this->acme->id]);

    $this->invoice = function (string $number, string $issued, ?string $due, string $subtotal, string $paid, CollectionStatus $status, ?Client $client, bool $linked, array $extra = []): HoldedInvoice {
        $total = bcmul($subtotal, '1.21', 2);
        $invoice = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'issued_on' => $issued, 'due_on' => $due,
            'client_id' => $client?->id, 'subtotal' => $subtotal, 'tax_total' => bcsub($total, $subtotal, 2), 'total' => $total,
            'paid_total' => $paid, 'pending_total' => bcsub($total, $paid, 2), 'collection_status' => $status, ...$extra,
        ]);
        if ($linked) {
            HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $this->project->id, 'method' => InvoiceLinkMethod::Manual]);
        }

        return $invoice;
    };

    $paid = ($this->invoice)('F260001', '2026-01-10', '2026-02-09', '1000.00', '1210.00', CollectionStatus::Paid, $this->acme, true);
    HoldedPayment::query()->create(['holded_id' => 'p-1', 'holded_invoice_id' => $paid->id, 'paid_on' => '2026-02-05', 'amount' => '1210.00']);
    ($this->invoice)('F260002', '2026-02-01', '2026-03-01', '2000.00', '0.00', CollectionStatus::Overdue, $this->acme, false);
    ($this->invoice)('F260003', '2026-03-05', '2026-04-04', '500.00', '0.00', CollectionStatus::Unpaid, $this->acme, false);
    ($this->invoice)('F250001', '2025-02-10', '2025-03-12', '1500.00', '1815.00', CollectionStatus::Paid, $this->acme, true);
    ($this->invoice)('F250090', '2025-11-01', '2025-12-01', '300.00', '0.00', CollectionStatus::Overdue, null, false, ['holded_contact_id' => 'c-x', 'contact_name' => 'Estudio Nébula']);
    ($this->invoice)('', '2026-03-10', null, '800.00', '0.00', CollectionStatus::Draft, $this->acme, false, ['number' => null, 'is_draft' => true]);
    HoldedContact::query()->create(['holded_id' => 'c-x', 'name' => 'Estudio Nébula, S.L.']);

    $banks = Project::factory()->hourBank()->create(['client_id' => $this->acme->id]);
    HourBank::factory()->create(['project_id' => $banks->id, 'total_minutes' => 600, 'consumed_minutes' => 540, 'status' => HourBankStatus::Active]);
    HourBank::factory()->create(['project_id' => $banks->id, 'total_minutes' => 600, 'consumed_minutes' => 300, 'status' => HourBankStatus::Active]);
});

it('calcula la portada a mano', function () {
    $summary = app(BillingSummary::class)->summary($this->admin, BillingSummary::period([]));

    expect($summary['period'])->toBe(['key' => 'anio', 'from' => '2026-01-01', 'to' => '2026-12-31'])
        ->and($summary['attention'])->toBe([
            'overdue' => ['count' => 2, 'amount' => '2783.00', 'oldest_days' => 109],
            // Las tres sin enlazar y el borrador (como la vista «Sin proyecto»).
            'unlinked' => ['count' => 4, 'amount' => '3600.00'],
            'contacts' => ['count' => 1, 'amount' => '300.00'],
            'banks' => ['count' => 1, 'over' => 0, 'threshold' => 85],
        ])
        ->and($summary['kpis'])->toBe([
            'invoiced' => '3500.00',
            // Del 1/1 al 20/3 de 2025 (D-404).
            'previous_invoiced' => '1500.00',
            'variation_pct' => '133.3',
            'invoices' => 3,
            'unbilled' => '0.00',
            'unbilled_clients' => 0,
            'outstanding' => '3388.00',
            'outstanding_count' => 3,
            'overdue' => '2783.00',
            'overdue_count' => 2,
        ])
        ->and($summary['receivable']['aging'])->toBe([
            ['key' => 'current', 'amount' => '605.00', 'count' => 1],
            ['key' => 'd1_30', 'amount' => '2420.00', 'count' => 1],
            ['key' => 'd31_60', 'amount' => '0.00', 'count' => 0],
            ['key' => 'd61_90', 'amount' => '0.00', 'count' => 0],
            ['key' => 'd90_plus', 'amount' => '363.00', 'count' => 1],
        ])
        ->and($summary['receivable']['clients'])->toBe([
            ['client' => ['id' => $this->acme->id, 'name' => 'Acme'], 'contact_name' => null, 'amount' => '3025.00', 'count' => 2, 'overdue_count' => 1],
            ['client' => null, 'contact_name' => 'Estudio Nébula', 'amount' => '363.00', 'count' => 1, 'overdue_count' => 1],
        ]);

    // Por mes, con IVA: lo facturado por la emisión y lo cobrado por la fecha del cobro.
    expect($summary['months'])->toHaveCount(12)
        ->and(array_slice($summary['months'], 0, 3))->toBe([
            ['month' => '2026-01', 'invoiced' => '1210.00', 'collected' => '0.00', 'previous' => '0.00'],
            ['month' => '2026-02', 'invoiced' => '2420.00', 'collected' => '1210.00', 'previous' => '1815.00'],
            ['month' => '2026-03', 'invoiced' => '605.00', 'collected' => '0.00', 'previous' => '0.00'],
        ])
        ->and($summary['report_query'])->toBe(['periodo' => 'anio']);
});

it('el periodo sale de la URL (sin «todo») y los enlaces a Ventas y Por facturar llevan el mismo', function () {
    expect(BillingSummary::period(['periodo' => 'anio-anterior']))->toBe(['key' => 'anio-anterior', 'from' => '2025-01-01', 'to' => '2025-12-31'])
        ->and(BillingSummary::reportQuery(BillingSummary::period(['periodo' => 'anio-anterior'])))->toBe(['periodo' => 'anio', 'fecha' => '2025-01-01'])
        ->and(BillingSummary::period(['periodo' => 'todo'])['key'])->toBe('anio')
        ->and(BillingSummary::reportQuery(BillingSummary::period(['periodo' => 'rango', 'desde' => '2026-02-01', 'hasta' => '2026-02-28'])))
        ->toBe(['periodo' => 'rango', 'desde' => '2026-02-01', 'hasta' => '2026-02-28']);

    $this->actingAs($this->admin)->get('/facturacion?periodo=mes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/summary')
            ->where('explicit_period', true)
            ->where('summary.period.key', 'mes')
            ->where('summary.kpis.invoiced', '500.00')
            ->has('summary.months', 1)
            ->where('can.viewBanks', true));
});

it('sin nada pendiente, «Requiere atención» está vacío (el «Todo al día» de la página)', function () {
    HoldedInvoice::query()->delete();
    HoldedContact::query()->delete();
    HourBank::query()->update(['consumed_minutes' => 0]);

    expect(app(BillingSummary::class)->summary($this->admin, BillingSummary::period([]))['attention'])->toBe([
        'overdue' => ['count' => 0, 'amount' => '0.00', 'oldest_days' => null],
        'unlinked' => ['count' => 0, 'amount' => '0.00'],
        'contacts' => ['count' => 0, 'amount' => '0.00'],
        'banks' => ['count' => 0, 'over' => 0, 'threshold' => 85],
    ]);
});
