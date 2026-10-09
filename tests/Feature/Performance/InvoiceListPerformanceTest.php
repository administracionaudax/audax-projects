<?php

use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Rendimiento del listado de facturas y de su ficha (D-406 a D-408): vistas, barra de importes,
| totales y orden agregados en SQL, sin N+1 (también las sugerencias de las filas sin enlazar).
|   1. cada página cabe en un presupuesto de consultas,
|   2. ninguna consulta se repite más de 4 veces (el síntoma de un N+1),
|   3. el número de consultas NO crece con los datos: con el doble de clientes, proyectos, bolsas,
|      facturas, líneas, enlaces y rectificativas, las mismas consultas.
| PERF_REPORT=1 vendor/bin/pest tests/Feature/Performance/InvoiceListPerformanceTest.php imprime lo medido.
*/

/** Presupuesto (lo medido + 3): las props compartidas, las vistas, la barra, los totales, la página y sus relaciones. */
const INVOICE_LIST_BUDGETS = [
    'listado' => 29,
    'vencidas.cliente' => 29,
    'búsqueda.servicio' => 30,
    'sin-proyecto' => 28,
    'ficha' => 29,
];

/** Por cliente: un proyecto de bolsas con dos bolsas, un fee y una factura al mes de 2025 y 2026. */
function seedInvoiceListData(int $clients, string $prefix): void
{
    $names = ['bolsadehoras', 'Fee MK y RRSS', 'Desarrollo', 'Diseño Producto UX/UI', 'Mantenimiento', 'SEO'];
    $n = 0;

    foreach (range(1, $clients) as $c) {
        $client = Client::factory()->create(['name' => "{$prefix} cliente {$c}"]);
        $banks = Project::factory()->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-BDH", 'billing_type' => BillingType::HourBank]);
        HourBank::factory()->create(['project_id' => $banks->id, 'start_date' => '2025-01-01']);
        HourBank::factory()->create(['project_id' => $banks->id, 'start_date' => '2026-01-01']);
        Project::factory()->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-FE1", 'billing_type' => BillingType::MonthlyFee]);

        foreach (['2025', '2026'] as $year) {
            foreach (range(1, 6) as $month) {
                $n++;
                $date = sprintf('%s-%02d-%02d', $year, $month * 2 - 1, 5 + ($c % 20));
                $overdue = $n % 3 === 0;
                $invoice = HoldedInvoice::query()->create([
                    'holded_id' => "{$prefix}-{$n}",
                    'kind' => HoldedDocumentKind::Invoice,
                    'number' => sprintf('F%s%s%04d', substr($year, 2), $prefix, $n),
                    'client_id' => $client->id,
                    'contact_name' => "{$prefix} contacto {$c}",
                    'issued_on' => $date,
                    'due_on' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
                    'subtotal' => '1000.00',
                    'tax_total' => '210.00',
                    'total' => '1210.00',
                    'paid_total' => $overdue ? '0.00' : '1210.00',
                    'pending_total' => $overdue ? '1210.00' : '0.00',
                    'collection_status' => $overdue ? CollectionStatus::Overdue : CollectionStatus::Paid,
                    'is_draft' => $n % 11 === 0,
                ]);

                HoldedInvoiceLine::query()->create([
                    'holded_invoice_id' => $invoice->id,
                    'position' => 1,
                    'name' => $names[$n % count($names)],
                    'units' => '1',
                    'unit_price' => '1000.00',
                    'discount_pct' => '0',
                    'subtotal' => '1000.00',
                    'tax_rate' => '21',
                ]);

                if ($n % 2 === 0) {
                    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $banks->id, 'method' => InvoiceLinkMethod::Manual]);
                }

                if ($n % 5 === 0) {
                    HoldedInvoice::query()->create([
                        'holded_id' => "{$prefix}-cn-{$n}",
                        'kind' => HoldedDocumentKind::CreditNote,
                        'number' => sprintf('CN%s%s%04d', substr($year, 2), $prefix, $n),
                        'client_id' => $client->id,
                        'issued_on' => $date,
                        'subtotal' => '-100.00',
                        'tax_total' => '-21.00',
                        'total' => '-121.00',
                        'paid_total' => '0.00',
                        'pending_total' => '0.00',
                        'collection_status' => CollectionStatus::Paid,
                        'rectified_invoice_id' => $invoice->id,
                    ]);
                }
            }
        }
    }
}

/**
 * @return array{total: int, max_repeats: int}
 */
function measureInvoiceList(TestCase $test, string $url): array
{
    $test->get($url)->assertOk();

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
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    seedInvoiceListData(12, 'A');
    $this->invoice = (int) HoldedInvoice::query()->where('number', 'like', 'F26A%')->orderBy('id')->value('id');
});

it('el listado de facturas y su ficha caben en su presupuesto de consultas y no crecen con los datos', function () {
    $urls = [
        'listado' => '/facturacion/facturas',
        'vencidas.cliente' => '/facturacion/facturas?vista=vencidas&orden=cliente&cobro=vencido',
        'búsqueda.servicio' => '/facturacion/facturas?buscar=cliente&servicio[]=fees&servicio[]=bolsas&periodo=todo&orden=pendiente',
        'sin-proyecto' => '/facturacion/facturas?vista=sin-proyecto&pagina=2',
        'ficha' => "/facturacion/facturas/{$this->invoice}?orden=total&dir=asc",
    ];

    $this->actingAs($this->admin);
    $before = [];
    foreach ($urls as $label => $url) {
        $before[$label] = measureInvoiceList($this, $url);
        if (getenv('PERF_REPORT')) {
            fwrite(STDERR, sprintf("%-20s %3d consultas (repetida como mucho %d)\n", $label, $before[$label]['total'], $before[$label]['max_repeats']));
        }

        expect($before[$label]['total'])->toBeLessThanOrEqual(INVOICE_LIST_BUDGETS[$label], "{$label}: {$before[$label]['total']} consultas")
            ->and($before[$label]['max_repeats'])->toBeLessThanOrEqual(4, "{$label}: una consulta se repite {$before[$label]['max_repeats']} veces");
    }

    // El doble de datos: las mismas consultas.
    seedInvoiceListData(12, 'B');

    foreach ($urls as $label => $url) {
        expect(measureInvoiceList($this, $url)['total'])->toBe($before[$label]['total'], "{$label} crece con los datos");
    }
});
