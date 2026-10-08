<?php

use App\Domain\Billing\InvoicingQuery;
use App\Domain\Billing\InvoicingReport;
use App\Enums\BillingService;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Informe de facturación (D-400): las cifras (facturado = emitidas − rectificativas sin restar dos
| veces, cobrado, pendiente, vencido, previsto, número y ticket medio), la comparación con el mismo
| periodo del año anterior, por mes, por servicio, el ranking de clientes (con «Sin cliente casado»),
| la antigüedad, las vencidas, los filtros de cliente y servicio, los permisos y las exportaciones.
|
| Hoy: 20/03/2026. Periodo por defecto: 2026 comparado con 2025.
|
| | Doc. | Cliente | Fecha | Base | Líneas | Cobro |
| |---|---|---|---|---|---|
| | F250001 | A | 20/01/2025 | 1.200 | Desarrollo | cobrada |
| | F260001 | A | 10/01/2026 | 1.000 | bolsadehoras 600 + Diseño Producto UX/UI 400 | cobrada (1.210) |
| | F260002 | A | 15/02/2026 | 500 | Fee MK y RRSS | pendiente 605, vence 01/03 (19 días) |
| | F260003 | B | 05/01/2026 | 2.000 | Desarrollo | pendiente 2.420, vence 05/01 (74 días) |
| | CN260001 | B | 20/02/2026 | −200 | Desarrollo (en positivo, como Holded) | rectifica F260003 |
| | F260004 | B | 01/02/2026 | 300 | Desarrollo | ANULADA |
| | CN260002 | B | 02/02/2026 | −300 | Desarrollo | rectifica la anulada: no resta |
| | F260005 | — | 01/03/2026 | 100 | Herramienta Figma | pendiente 121, vence 01/04 |
| | borrador | A | 29/03/2026 | 700 | Fee MK y RRSS | previsto |
*/

function invoicingDoc(array $attributes, array $lines = []): HoldedInvoice
{
    static $sequence = 0;
    $sequence++;
    $subtotal = $attributes['subtotal'];
    $credit = ($attributes['kind'] ?? HoldedDocumentKind::Invoice) === HoldedDocumentKind::CreditNote;
    $total = bcmul($subtotal, '1.21', 2);

    $invoice = HoldedInvoice::query()->create([
        'holded_id' => 'doc-'.$sequence,
        'kind' => HoldedDocumentKind::Invoice,
        'number' => null,
        'issued_on' => '2026-01-01',
        'due_on' => null,
        'tax_total' => bcsub($total, $subtotal, 2),
        'total' => $total,
        'paid_total' => '0.00',
        'pending_total' => $credit ? '0.00' : $total,
        'collection_status' => CollectionStatus::Unpaid,
        ...$attributes,
    ]);

    foreach ($lines as $index => [$name, $amount]) {
        HoldedInvoiceLine::query()->create([
            'holded_invoice_id' => $invoice->id,
            'position' => $index + 1,
            'name' => $name,
            'units' => '1',
            'unit_price' => $amount,
            'discount_pct' => '0',
            'subtotal' => $amount,
            'tax_rate' => '21',
        ]);
    }

    return $invoice;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();

    $this->admin = userWithRole('admin');
    $this->a = Client::factory()->create(['name' => 'Alfa Estudio']);
    $this->b = Client::factory()->create(['name' => 'Beta Foods']);

    invoicingDoc(['number' => 'F250001', 'client_id' => $this->a->id, 'issued_on' => '2025-01-20', 'subtotal' => '1200.00', 'paid_total' => '1452.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Paid], [['Desarrollo', '1200.00']]);
    invoicingDoc(['number' => 'F260001', 'client_id' => $this->a->id, 'issued_on' => '2026-01-10', 'due_on' => '2026-02-09', 'subtotal' => '1000.00', 'paid_total' => '1210.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Paid],
        [['bolsadehoras', '600.00'], ['Diseño Producto UX/UI', '400.00']]);
    $this->f2 = invoicingDoc(['number' => 'F260002', 'client_id' => $this->a->id, 'issued_on' => '2026-02-15', 'due_on' => '2026-03-01', 'subtotal' => '500.00', 'collection_status' => CollectionStatus::Overdue], [['Fee MK y RRSS', '500.00']]);
    $this->f3 = invoicingDoc(['number' => 'F260003', 'client_id' => $this->b->id, 'issued_on' => '2026-01-05', 'due_on' => '2026-01-05', 'subtotal' => '2000.00', 'collection_status' => CollectionStatus::Overdue], [['Desarrollo', '2000.00']]);
    invoicingDoc(['number' => 'CN260001', 'kind' => HoldedDocumentKind::CreditNote, 'client_id' => $this->b->id, 'issued_on' => '2026-02-20', 'subtotal' => '-200.00', 'rectified_invoice_id' => $this->f3->id], [['Desarrollo', '200.00']]);
    $f4 = invoicingDoc(['number' => 'F260004', 'client_id' => $this->b->id, 'issued_on' => '2026-02-01', 'subtotal' => '300.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Cancelled], [['Desarrollo', '300.00']]);
    invoicingDoc(['number' => 'CN260002', 'kind' => HoldedDocumentKind::CreditNote, 'client_id' => $this->b->id, 'issued_on' => '2026-02-02', 'subtotal' => '-300.00', 'rectified_invoice_id' => $f4->id], [['Desarrollo', '300.00']]);
    invoicingDoc(['number' => 'F260005', 'client_id' => null, 'contact_name' => 'Gamma sin casar', 'issued_on' => '2026-03-01', 'due_on' => '2026-04-01', 'subtotal' => '100.00'], [['Herramienta Figma', '100.00']]);
    invoicingDoc(['client_id' => $this->a->id, 'issued_on' => '2026-03-29', 'subtotal' => '700.00', 'is_draft' => true, 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Draft], [['Fee MK y RRSS', '700.00']]);

    $this->report = fn (array $query = []): array => app(InvoicingReport::class)->report(InvoicingQuery::fromQuery($query));
});

it('clasifica las líneas por el servicio del catálogo de Holded', function (?string $name, ?string $code, BillingService $expected) {
    expect(BillingService::classify($name, $code))->toBe($expected);
})->with([
    ['bolsadehoras', null, BillingService::HourBanks],
    ['Bolsa de horas 20 h', null, BillingService::HourBanks],
    ['Lo que sea', 'BDH', BillingService::HourBanks],
    ['Fee Producto digital', null, BillingService::Fees],
    ['Fee MK y RRSS', 'FMKRRSS', BillingService::Fees],
    ['Desarrollo', 'DES', BillingService::Development],
    ['Diseño Producto UX/UI', null, BillingService::Design],
    ['DISEÑO GRÁFICO', null, BillingService::Design],
    ['Mantenimiento web', null, BillingService::Maintenance],
    ['Auditoría de accesibilidad', null, BillingService::Audits],
    ['Auditorías UX', null, BillingService::Audits],
    ['SEO', null, BillingService::Seo],
    ['Posicionamiento', 'SEO', BillingService::Seo],
    ['Deseo', null, BillingService::Other],
    ['Herramienta Figma', null, BillingService::Tools],
    ['Inversión en medios', null, BillingService::PassThrough],
    ['Formación', null, BillingService::Other],
    [null, null, BillingService::Other],
]);

it('calcula las cifras del periodo: emitidas menos rectificativas, sin restar dos veces la anulada', function () {
    $report = ($this->report)();

    expect($report['from'])->toBe('2026-01-01')
        ->and($report['to'])->toBe('2026-12-31')
        ->and($report['compare'])->toBeTrue()
        ->and($report['previous_from'])->toBe('2025-01-01')
        ->and($report['kpis'])->toMatchArray([
            'invoiced' => '3400.00',
            'previous_invoiced' => '1200.00',
            'variation_pct' => '183.3',
            'collected' => '1210.00',
            'outstanding' => '3146.00',
            'overdue' => '3025.00',
            'planned' => '700.00',
            'planned_count' => 1,
            'count' => 4,
            'previous_count' => 1,
            'average' => '850.00',
            'previous_average' => '1200.00',
            'credit_notes' => '-200.00',
        ]);
});

it('reparte lo facturado por mes con el mismo mes del año anterior y lo previsto', function () {
    $months = collect(($this->report)()['months'])->keyBy('month');

    expect($months)->toHaveCount(12)
        ->and($months['2026-01'])->toBe(['month' => '2026-01', 'invoiced' => '3000.00', 'planned' => '0.00', 'previous' => '1200.00', 'count' => 2])
        ->and($months['2026-02'])->toBe(['month' => '2026-02', 'invoiced' => '300.00', 'planned' => '0.00', 'previous' => '0.00', 'count' => 1])
        ->and($months['2026-03'])->toBe(['month' => '2026-03', 'invoiced' => '100.00', 'planned' => '700.00', 'previous' => '0.00', 'count' => 1])
        ->and($months['2026-12']['invoiced'])->toBe('0.00');

    // Sin comparar, sin la serie del año anterior (pero la variación sigue en las cifras).
    $plain = ($this->report)(['periodo' => 'anio', 'fecha' => '2026-03-20']);
    expect($plain['compare'])->toBeFalse()
        ->and($plain['months'][0]['previous'])->toBeNull()
        ->and($plain['kpis']['variation_pct'])->toBe('183.3')
        ->and($plain['kpis']['previous_count'])->toBeNull();
});

it('desglosa por servicio, con las líneas de las rectificativas restando', function () {
    expect(($this->report)()['services'])->toBe([
        ['key' => 'desarrollo', 'amount' => '1800.00', 'share' => '52.9'],
        ['key' => 'bolsas', 'amount' => '600.00', 'share' => '17.6'],
        ['key' => 'fees', 'amount' => '500.00', 'share' => '14.7'],
        ['key' => 'diseno', 'amount' => '400.00', 'share' => '11.8'],
        ['key' => 'herramientas', 'amount' => '100.00', 'share' => '2.9'],
    ]);
});

it('lo que no está en ninguna línea sale como «Sin desglose por línea»', function () {
    invoicingDoc(['number' => 'F260006', 'client_id' => $this->a->id, 'issued_on' => '2026-03-02', 'subtotal' => '50.00'], [['Desarrollo', '80.00']]);

    $services = collect(($this->report)()['services'])->keyBy('key');

    expect($services['desarrollo']['amount'])->toBe('1880.00')
        ->and($services['sin_desglose']['amount'])->toBe('-30.00');
});

it('ordena los clientes, suma el resto y deja aparte las facturas sin cliente casado', function () {
    $clients = ($this->report)()['clients'];

    expect($clients['top'])->toBe([
        ['id' => $this->b->id, 'name' => 'Beta Foods', 'amount' => '1800.00', 'share' => '52.9', 'count' => 1],
        ['id' => $this->a->id, 'name' => 'Alfa Estudio', 'amount' => '1500.00', 'share' => '44.1', 'count' => 2],
    ])
        ->and($clients['rest'])->toBeNull()
        ->and($clients['unmatched'])->toBe(['amount' => '100.00', 'share' => '2.9', 'count' => 1]);

    // Con más de 10 clientes, los que sobran van a «Resto».
    foreach (range(1, 10) as $i) {
        $client = Client::factory()->create(['name' => sprintf('Cliente %02d', $i)]);
        invoicingDoc(['client_id' => $client->id, 'issued_on' => '2026-03-05', 'subtotal' => (string) (10 * $i).'.00']);
    }

    $clients = ($this->report)()['clients'];
    expect($clients['top'])->toHaveCount(10)
        ->and($clients['top'][9]['name'])->toBe('Cliente 03')
        ->and($clients['rest'])->toBe(['amount' => '30.00', 'share' => '0.8', 'clients' => 2, 'count' => 2]);
});

it('reparte lo pendiente por antigüedad y lista las vencidas por cliente', function () {
    $report = ($this->report)();

    expect(collect($report['aging'])->mapWithKeys(fn (array $bucket): array => [$bucket['key'] => [$bucket['amount'], $bucket['count']]])->all())->toBe([
        'current' => ['121.00', 1],
        'd1_30' => ['605.00', 1],
        'd31_60' => ['0.00', 0],
        'd61_90' => ['2420.00', 1],
        'd90_plus' => ['0.00', 0],
    ]);

    expect($report['overdue']['total'])->toBe(2)
        ->and($report['overdue']['clients'][0]['client'])->toBe(['id' => $this->b->id, 'name' => 'Beta Foods'])
        ->and($report['overdue']['clients'][0]['invoices'][0])->toMatchArray(['id' => $this->f3->id, 'number' => 'F260003', 'due_on' => '2026-01-05', 'days' => 74, 'pending' => '2420.00'])
        ->and($report['overdue']['clients'][1]['client']['name'])->toBe('Alfa Estudio')
        ->and($report['overdue']['clients'][1]['invoices'][0])->toMatchArray(['id' => $this->f2->id, 'days' => 19, 'pending' => '605.00']);
});

it('los tramos cambian el día que toca: hoy vence, sin vencer; ayer, 1 día', function () {
    invoicingDoc(['number' => 'F260010', 'client_id' => $this->a->id, 'issued_on' => '2026-03-01', 'due_on' => '2026-03-20', 'subtotal' => '10.00']);
    invoicingDoc(['number' => 'F260011', 'client_id' => $this->a->id, 'issued_on' => '2026-03-01', 'due_on' => '2026-03-19', 'subtotal' => '20.00']);
    invoicingDoc(['number' => 'F260012', 'client_id' => $this->a->id, 'issued_on' => '2026-01-01', 'due_on' => '2025-12-19', 'subtotal' => '30.00']);

    $aging = collect(($this->report)()['aging'])->keyBy('key');

    expect($aging['current']['amount'])->toBe('133.10')
        ->and($aging['d1_30']['amount'])->toBe('629.20')
        ->and($aging['d90_plus']['amount'])->toBe('36.30');
});

it('filtra por cliente y por servicio', function () {
    $alfa = ($this->report)(['cliente' => [$this->a->id]]);
    expect($alfa['kpis']['invoiced'])->toBe('1500.00')
        ->and($alfa['kpis']['planned'])->toBe('700.00')
        ->and($alfa['clients']['unmatched'])->toBeNull()
        ->and($alfa['overdue']['total'])->toBe(1);

    $development = ($this->report)(['servicio' => ['desarrollo']]);
    expect($development['services_filter'])->toBe(['desarrollo'])
        ->and($development['kpis'])->toMatchArray([
            'invoiced' => '1800.00',
            'previous_invoiced' => '1200.00',
            'count' => 1,
            'collected' => '0.00',
            'outstanding' => '2420.00',
            'credit_notes' => '-200.00',
        ])
        ->and($development['services'])->toBe([['key' => 'desarrollo', 'amount' => '1800.00', 'share' => '100.0']])
        ->and($development['clients']['top'])->toHaveCount(1);

    $fees = ($this->report)(['servicio' => ['fees', 'nada']]);
    expect($fees['kpis']['invoiced'])->toBe('500.00')
        ->and($fees['kpis']['planned'])->toBe('700.00');

    // Un servicio sin ninguna línea: todo a cero, sin errores.
    expect(($this->report)(['servicio' => ['seo']])['kpis']['invoiced'])->toBe('0.00');
});

it('compara un trimestre con el mismo trimestre del año anterior', function () {
    $report = ($this->report)(['periodo' => 'trimestre', 'fecha' => '2026-02-10', 'comparar' => '1']);

    expect($report['from'])->toBe('2026-01-01')
        ->and($report['to'])->toBe('2026-03-31')
        ->and($report['previous_from'])->toBe('2025-01-01')
        ->and($report['previous_to'])->toBe('2025-03-31')
        ->and($report['months'])->toHaveCount(3)
        ->and($report['kpis']['previous_invoiced'])->toBe('1200.00');
});

it('la página: solo con view-billing, con el año en curso comparado por defecto', function () {
    $finance = userWithRole('employee');
    $finance->givePermissionTo(Permission::ViewFinancials->value);

    $this->actingAs($finance)->get('/facturacion/informe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/report')
            ->where('filters.period', 'anio')
            ->where('filters.compare', true)
            ->where('filters.comparison', ['from' => '2025-01-01', 'to' => '2025-12-31'])
            ->where('filters.query.periodo', 'anio')
            ->where('report.kpis.invoiced', '3400.00')
            ->where('report_request.kind', 'invoicing')
            ->has('services', 10));

    $this->actingAs($finance)->get('/facturacion/informe?periodo=mes&fecha=2026-02-01&servicio[]=fees')
        ->assertInertia(fn (Assert $page) => $page->where('filters.compare', false)
            ->where('filters.comparison', null)
            ->where('filters.query.servicio', ['fees'])
            ->where('report.kpis.invoiced', '500.00'));

    foreach ([userWithRole('department_manager'), userWithRole('employee'), User::factory()->collaborator()->create()] as $user) {
        $this->actingAs($user)->get('/facturacion/informe')->assertForbidden();
    }

    enableBilling(false);
    $this->actingAs($this->admin)->get('/facturacion/informe')->assertNotFound();
});

it('exporta en Excel, CSV, PDF y para imprimir con los mismos filtros', function () {
    $url = '/facturacion/informe?periodo=anio&fecha=2026-01-01&comparar=1';

    $this->actingAs($this->admin)->get($url.'&formato=xlsx')->assertOk()->streamedContent();

    $csv = $this->actingAs($this->admin)->get($url.'&formato=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Facturado sin IVA')->toContain('2026-01')->toContain('3000')->toContain('Total');

    $clients = $this->actingAs($this->admin)->get($url.'&formato=csv&tabla=clientes')->streamedContent();
    expect($clients)->toContain('Beta Foods')->toContain('Sin cliente casado');

    $overdue = $this->actingAs($this->admin)->get($url.'&formato=csv&tabla=vencidas')->streamedContent();
    expect($overdue)->toContain('F260003')->toContain('74');

    $this->actingAs($this->admin)->get($url.'&formato=pdf')->assertOk();
    $this->actingAs($this->admin)->get($url.'&formato=imprimir')->assertOk()
        ->assertSee('Informe de facturación')
        ->assertSee('Beta Foods')
        ->assertSee('Antigüedad de lo pendiente');

    $manager = userWithRole('department_manager');
    $this->actingAs($manager)->get($url.'&formato=csv')->assertForbidden();
});
