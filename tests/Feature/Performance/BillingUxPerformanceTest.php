<?php

use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\HourBankStatus;
use App\Enums\InvoiceLinkMethod;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedPayment;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Rendimiento del tramo 2 del rediseño de Facturación (I1, I5 e I10): el Resumen, la bandeja «Por
| revisar» (sus dos pestañas) y la lista de «Por facturar», todo agregado en SQL y sin N+1:
|   1. cada página cabe en un presupuesto de consultas,
|   2. ninguna consulta se repite más de 4 veces (el síntoma de un N+1),
|   3. el número de consultas NO crece con los datos: con el doble de clientes, proyectos, bolsas,
|      horas, facturas, líneas, enlaces, cobros y contactos, las mismas consultas.
| PERF_REPORT=1 vendor/bin/pest tests/Feature/Performance/BillingUxPerformanceTest.php imprime lo medido.
*/

/** Presupuesto (lo medido + 3, en frío: con la caché de la navegación vacía): las props compartidas (unas 20) y las de cada pantalla. */
const BILLING_UX_BUDGETS = [
    'resumen' => 53,
    'resumen.mes' => 51,
    'por-revisar.contactos' => 31,
    'por-revisar.facturas' => 34,
    'por-facturar' => 42,
];

/** Por cliente: un proyecto por horas, uno de bolsas con su bolsa, un fee, horas, facturas, cobros y un contacto. */
function seedBillingUxData(int $clients, string $prefix, User $worker): void
{
    $n = 0;

    foreach (range(1, $clients) as $c) {
        $client = Client::factory()->create(['name' => "{$prefix} cliente {$c}", 'tax_id' => sprintf('B%s%07d', $prefix === 'A' ? '1' : '2', $c)]);
        $hourly = Project::factory()->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-HOR", 'hourly_rate' => '60.00', 'start_date' => '2025-01-01']);
        $bankProject = Project::factory()->hourBank()->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-BDH", 'start_date' => '2025-01-01']);
        $bank = HourBank::factory()->create(['project_id' => $bankProject->id, 'start_date' => '2026-01-01', 'total_minutes' => 600, 'consumed_minutes' => 560, 'price_amount' => '500.00', 'status' => HourBankStatus::Active]);
        Project::factory()->monthlyFee(600, '300.00')->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-FE1", 'start_date' => '2026-01-01', 'billing_type' => BillingType::MonthlyFee]);

        foreach ([[$hourly, null, 0], [$bankProject, $bank, 60]] as [$project, $hourBank, $overage]) {
            $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $hourBank?->id]);
            foreach (['2026-01-15', '2026-02-15', '2026-03-10'] as $date) {
                TimeEntry::factory()->forTask($task)->minutes(240)->status(TimeEntryStatus::Approved)->on($date)
                    ->create(['user_id' => $worker->id, 'is_billable' => true, 'overage_minutes' => $overage, 'hourly_rate_snapshot' => '60.00', 'hourly_cost_snapshot' => '30.00']);
            }
        }

        HoldedContact::query()->create(['holded_id' => "{$prefix}-ok-{$c}", 'name' => $client->name, 'client_id' => $client->id, 'match_method' => HoldedContact::MATCH_NAME]);
        HoldedContact::query()->create(['holded_id' => "{$prefix}-new-{$c}", 'name' => "{$prefix} contacto nuevo {$c}"]);

        foreach (['2025-02-10', '2026-01-10', '2026-02-10', '2026-03-05'] as $i => $date) {
            $n++;
            $paid = $i % 2 === 0;
            $invoice = HoldedInvoice::query()->create([
                'holded_id' => "{$prefix}-{$n}", 'kind' => HoldedDocumentKind::Invoice, 'number' => sprintf('F%s%04d', $prefix, $n),
                'holded_contact_id' => $i === 3 ? "{$prefix}-new-{$c}" : "{$prefix}-ok-{$c}", 'client_id' => $i === 3 ? null : $client->id,
                'contact_name' => $client->name, 'issued_on' => $date, 'due_on' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
                'subtotal' => '1000.00', 'tax_total' => '210.00', 'total' => '1210.00',
                'paid_total' => $paid ? '1210.00' : '0.00', 'pending_total' => $paid ? '0.00' : '1210.00',
                'collection_status' => $paid ? CollectionStatus::Paid : CollectionStatus::Overdue,
            ]);
            HoldedInvoiceLine::query()->create(['holded_invoice_id' => $invoice->id, 'position' => 1, 'name' => $i === 1 ? 'Desarrollo' : 'bolsadehoras',
                'units' => '4', 'unit_price' => '250.00', 'discount_pct' => '0', 'subtotal' => '1000.00', 'tax_rate' => '21']);
            if ($paid) {
                HoldedPayment::query()->create(['holded_id' => "{$prefix}-p-{$n}", 'holded_invoice_id' => $invoice->id, 'paid_on' => CarbonImmutable::parse($date)->addDays(10)->toDateString(), 'amount' => '1210.00']);
            }
            if ($i < 2) {
                HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $i === 1 ? $hourly->id : $bankProject->id, 'hour_bank_id' => $i === 1 ? null : $bank->id, 'method' => InvoiceLinkMethod::Manual]);
            }
        }
    }
}

/**
 * @return array{total: int, max_repeats: int}
 */
function measureBillingUx(TestCase $test, string $url): array
{
    $test->get($url)->assertOk();
    Cache::flush();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $test->get($url)->assertOk();
    app('events')->forget(QueryExecuted::class);
    if (getenv('PERF_QUERIES')) {
        fwrite(STDERR, implode(PHP_EOL, $queries).PHP_EOL.PHP_EOL);
    }

    return ['total' => count($queries), 'max_repeats' => $queries === [] ? 0 : max(array_count_values($queries))];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->worker = userWithRole('employee');
    seedBillingUxData(8, 'A', $this->worker);
});

it('el Resumen, Por revisar y Por facturar caben en su presupuesto de consultas y no crecen con los datos', function () {
    $urls = [
        'resumen' => '/facturacion',
        'resumen.mes' => '/facturacion?periodo=mes',
        'por-revisar.contactos' => '/facturacion/por-revisar?tipo=contactos',
        'por-revisar.facturas' => '/facturacion/por-revisar?tipo=facturas',
        'por-facturar' => '/facturacion/por-facturar',
    ];

    $this->actingAs($this->admin);
    $before = [];
    foreach ($urls as $label => $url) {
        $before[$label] = measureBillingUx($this, $url);
        if (getenv('PERF_REPORT')) {
            fwrite(STDERR, sprintf("%-24s %3d consultas (repetida como mucho %d)\n", $label, $before[$label]['total'], $before[$label]['max_repeats']));
        }

        expect($before[$label]['total'])->toBeLessThanOrEqual(BILLING_UX_BUDGETS[$label], "{$label}: {$before[$label]['total']} consultas")
            ->and($before[$label]['max_repeats'])->toBeLessThanOrEqual(4, "{$label}: una consulta se repite {$before[$label]['max_repeats']} veces");
    }

    // El doble de datos: las mismas consultas.
    seedBillingUxData(8, 'B', $this->worker);

    foreach ($urls as $label => $url) {
        expect(measureBillingUx($this, $url)['total'])->toBe($before[$label]['total'], "{$label} crece con los datos");
    }
});
