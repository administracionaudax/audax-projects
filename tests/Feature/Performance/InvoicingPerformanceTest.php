<?php

use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Models\CatalogService;
use App\Models\Project;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
| Rendimiento de la emisión propia (PLAN-EMISION E1; D-427 y D-428): el listado unificado con las
| propias, la ficha de una propia, el editor (nuevo y borrador) y los ajustes de la emisión.
|   1. cada página cabe en un presupuesto de consultas,
|   2. ninguna consulta se repite más de 4 veces (el síntoma de un N+1),
|   3. el número de consultas NO crece con los datos (el doble de clientes, proyectos, facturas,
|      líneas, enlaces, rectificativas y borradores).
| PERF_REPORT=1 vendor/bin/pest tests/Feature/Performance/InvoicingPerformanceTest.php imprime lo medido.
*/

/** Presupuesto (lo medido + 3). */
const OWN_INVOICING_BUDGETS = [
    'listado.audax' => 26,
    'listado.pruebas' => 25,
    'ficha' => 34,
    'ficha.borrador' => 39,
    'editor.nuevo' => 28,
    'editor.borrador' => 30,
    'ajustes.series' => 29,
];

function seedOwnInvoices(TestCase $test, int $clients, string $prefix): void
{
    $admin = $test->admin;

    foreach (range(1, $clients) as $c) {
        $client = invoicingClient([], ['name' => "{$prefix} cliente {$c}"]);
        $project = Project::factory()->create(['client_id' => $client->id, 'code' => "{$prefix}{$c}-WEB"]);
        $lines = [invoicingLine('8', '60', 'iva_21', '0', 'Desarrollo'), invoicingLine('2', '50', 'iva_10', '5', 'Diseño'), invoicingLine('1', '100', 'exempt', '0', 'Formación')];

        $first = invoicingIssue($admin, $client, $lines, ['project_id' => $project->id]);
        invoicingIssue($admin, $client, $lines, ['project_id' => $project->id]);
        invoicingIssue($admin, $client, $lines, ['series_id' => invoicingSeries('PRU')->id]);
        invoicingDraft($admin, $client, $lines, ['project_id' => $project->id]);
        app(InvoiceCorrections::class)->cancel($first, $admin, 'Rehacer la factura');
    }
}

/**
 * @return array{total: int, max_repeats: int}
 */
function measureOwnInvoicing(TestCase $test, string $url): array
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
    $this->travelTo(CarbonImmutable::parse('2027-03-08 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    foreach (['DES' => 'Desarrollo', 'D_UX_UI' => 'Diseño Producto UX/UI', 'BDH' => 'bolsadehoras'] as $code => $name) {
        CatalogService::query()->create(['code' => $code, 'name' => $name, 'unit' => 'hour', 'unit_price' => '60', 'tax_rate_id' => invoicingTax('iva_21')->id]);
    }
    seedOwnInvoices($this, 6, 'A');
    $this->issued = SalesDocument::query()->where('status', 'issued')->where('type', 'invoice')->where('is_test', false)->orderBy('id')->firstOrFail();
    $this->draft = SalesDocument::query()->where('status', 'draft')->orderBy('id')->firstOrFail();
});

it('la emisión propia cabe en su presupuesto de consultas y no crece con los datos', function () {
    $urls = [
        'listado.audax' => '/facturacion/facturas?origen=audax&periodo=todo',
        'listado.pruebas' => '/facturacion/facturas?vista=pruebas',
        'ficha' => "/facturacion/documentos/{$this->issued->id}",
        'ficha.borrador' => "/facturacion/documentos/{$this->draft->id}",
        'editor.nuevo' => '/facturacion/facturas/nueva',
        'editor.borrador' => "/facturacion/documentos/{$this->draft->id}/editar",
        'ajustes.series' => '/facturacion/ajustes?apartado=series',
    ];

    $this->actingAs($this->admin);
    $before = [];
    foreach ($urls as $label => $url) {
        $before[$label] = measureOwnInvoicing($this, $url);
        if (getenv('PERF_REPORT')) {
            fwrite(STDERR, sprintf("%-20s %3d consultas (repetida como mucho %d)\n", $label, $before[$label]['total'], $before[$label]['max_repeats']));
        }

        expect($before[$label]['total'])->toBeLessThanOrEqual(OWN_INVOICING_BUDGETS[$label], "{$label}: {$before[$label]['total']} consultas")
            ->and($before[$label]['max_repeats'])->toBeLessThanOrEqual(4, "{$label}: una consulta se repite {$before[$label]['max_repeats']} veces");
    }

    seedOwnInvoices($this, 6, 'B');

    foreach ($urls as $label => $url) {
        expect(measureOwnInvoicing($this, $url)['total'])->toBe($before[$label]['total'], "{$label} crece con los datos");
    }
});
