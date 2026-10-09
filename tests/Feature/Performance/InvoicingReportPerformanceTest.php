<?php

use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Rendimiento del informe de facturación (D-400): todo agregado en SQL, sin N+1.
|   1. la página, con y sin filtro de servicio, y la exportación caben en un presupuesto de consultas,
|   2. ninguna consulta se repite más de 4 veces (el síntoma de un N+1),
|   3. el número de consultas NO crece con los datos: con el doble de clientes, facturas, líneas,
|      rectificativas, borradores y vencidas, las mismas consultas.
| PERF_REPORT=1 vendor/bin/pest tests/Feature/Performance/InvoicingReportPerformanceTest.php imprime lo medido.
*/

/** Presupuesto (lo medido + 3): las props compartidas, la última lectura de Holded y el informe. */
const INVOICING_BUDGETS = [
    'billing.report' => 29,
    'billing.report.service' => 30,
    'billing.report.client' => 29,
    'billing.report.export' => 15,
];

/** Facturas de ejemplo: por cliente, una al mes de 2025 y 2026 con dos líneas, y algunas rectificativas, vencidas y borradores. */
function seedInvoicingData(int $clients, string $prefix): void
{
    $names = ['bolsadehoras', 'Fee MK y RRSS', 'Desarrollo', 'Diseño Producto UX/UI', 'Mantenimiento', 'SEO', 'Herramienta Figma', 'Inversión Meta Ads', 'Formación'];
    $n = 0;

    foreach (range(1, $clients) as $c) {
        $client = Client::factory()->create(['name' => "{$prefix} cliente {$c}"]);

        foreach (['2025', '2026'] as $year) {
            foreach (range(1, 6) as $month) {
                $n++;
                $date = sprintf('%s-%02d-%02d', $year, $month * 2 - 1, 5 + $c);
                $invoice = HoldedInvoice::query()->create([
                    'holded_id' => "{$prefix}-{$n}",
                    'kind' => HoldedDocumentKind::Invoice,
                    'number' => sprintf('F%s%s%04d', substr($year, 2), $prefix, $n),
                    'client_id' => $c % 7 === 0 ? null : $client->id,
                    'contact_name' => "{$prefix} contacto {$c}",
                    'issued_on' => $date,
                    'due_on' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
                    'subtotal' => '1000.00',
                    'tax_total' => '210.00',
                    'total' => '1210.00',
                    'paid_total' => $n % 3 === 0 ? '0.00' : '1210.00',
                    'pending_total' => $n % 3 === 0 ? '1210.00' : '0.00',
                    'collection_status' => $n % 3 === 0 ? CollectionStatus::Overdue : CollectionStatus::Paid,
                    'is_draft' => $n % 11 === 0,
                ]);

                foreach ([0, 1] as $line) {
                    HoldedInvoiceLine::query()->create([
                        'holded_invoice_id' => $invoice->id,
                        'position' => $line + 1,
                        'name' => $names[($n + $line) % count($names)],
                        'units' => '1',
                        'unit_price' => '500.00',
                        'discount_pct' => '0',
                        'subtotal' => '500.00',
                        'tax_rate' => '21',
                    ]);
                }

                if ($n % 5 === 0) {
                    HoldedInvoice::query()->create([
                        'holded_id' => "{$prefix}-cn-{$n}",
                        'kind' => HoldedDocumentKind::CreditNote,
                        'number' => sprintf('CN%s%s%04d', substr($year, 2), $prefix, $n),
                        'client_id' => $invoice->client_id,
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
function measureInvoicing(TestCase $test, string $url): array
{
    $test->get($url)->assertOk();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $test->get($url);
    $response->assertOk();
    if (str_contains($url, 'formato=')) {
        $response->streamedContent();
    }
    app('events')->forget(QueryExecuted::class);

    return ['total' => count($queries), 'max_repeats' => $queries === [] ? 0 : max(array_count_values($queries))];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    seedInvoicingData(12, 'A');
    $this->firstClient = (int) Client::query()->orderBy('id')->value('id');
});

it('el informe de facturación cabe en su presupuesto de consultas y no crece con los datos', function () {
    $urls = [
        'billing.report' => '/facturacion/ventas',
        'billing.report.service' => '/facturacion/ventas?periodo=anio&fecha=2026-01-01&comparar=1&servicio[]=desarrollo&servicio[]=fees',
        'billing.report.client' => '/facturacion/ventas?periodo=anio&fecha=2026-01-01&comparar=1&cliente[]='.$this->firstClient,
        'billing.report.export' => '/facturacion/ventas?periodo=anio&fecha=2026-01-01&comparar=1&formato=csv',
    ];

    $this->actingAs($this->admin);
    $before = [];
    foreach ($urls as $label => $url) {
        $before[$label] = measureInvoicing($this, $url);
        if (getenv('PERF_REPORT')) {
            fwrite(STDERR, sprintf("%-26s %3d consultas (repetida como mucho %d)\n", $label, $before[$label]['total'], $before[$label]['max_repeats']));
        }

        expect($before[$label]['total'])->toBeLessThanOrEqual(INVOICING_BUDGETS[$label], "{$label}: {$before[$label]['total']} consultas")
            ->and($before[$label]['max_repeats'])->toBeLessThanOrEqual(4, "{$label}: una consulta se repite {$before[$label]['max_repeats']} veces");
    }

    // El doble de datos: las mismas consultas.
    seedInvoicingData(12, 'B');

    foreach ($urls as $label => $url) {
        expect(measureInvoicing($this, $url)['total'])->toBe($before[$label]['total'], "{$label} crece con los datos");
    }
});
