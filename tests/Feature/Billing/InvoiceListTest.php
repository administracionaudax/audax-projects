<?php

use App\Domain\Billing\BillingNav;
use App\Domain\Billing\InvoiceList;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Models\HoldedInvoiceLink;
use App\Models\HoldedSyncRun;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Listado de facturas (D-406 y D-407) y su ficha (D-408): vistas con su número y su periodo por
| defecto, los parámetros de antes como alias, la barra de importes que filtra, los totales al pie,
| el orden por columnas (los vacíos al final), la búsqueda, el filtro de servicio, «anterior» y
| «siguiente» dentro del listado del que vienes y la prop compartida de la navegación (D-409).
|
| Hoy: 20/03/2026.
|
| | Doc. | Cliente | Fecha | Vence | Total | Cobrado | Pendiente | Estado | Enlace | Línea |
| |---|---|---|---|---|---|---|---|---|---|
| | F250010 | Alfa | 10/11/2025 | 10/12/2025 | 1.210 | 0 | 1.210 | vencida | — | Desarrollo |
| | F260001 | Alfa | 10/01/2026 | 10/02/2026 | 1.210 | 1.210 | 0 | cobrada | ALF-WEB | Desarrollo web |
| | F260002 | Beta | 15/02/2026 | 01/03/2026 | 605 | 0 | 605 | pendiente (Holded aún no la ha marcado vencida) | — | Fee MK y RRSS |
| | F260003 | Beta | 05/03/2026 | 05/04/2026 | 2.420 | 1.000 | 1.420 | cobrada en parte | — | bolsadehoras |
| | F260004 | Alfa | 01/02/2026 | — | 363 | 0 | 0 | anulada | — | Desarrollo |
| | CN260001 | Beta | 20/02/2026 | — | −242 | 0 | 0 | rectifica F260003 | — | Desarrollo |
| | borrador | Alfa | 29/03/2026 | — | 847 | 0 | 0 | borrador | — | Fee MK y RRSS |
*/

function listInvoice(array $attributes, string $line): HoldedInvoice
{
    static $sequence = 0;
    $sequence++;

    $invoice = HoldedInvoice::query()->create([
        'holded_id' => 'list-'.$sequence,
        'kind' => HoldedDocumentKind::Invoice,
        'number' => null,
        'due_on' => null,
        'paid_total' => '0.00',
        'collection_status' => CollectionStatus::Unpaid,
        ...$attributes,
        'tax_total' => bcsub($attributes['total'], $attributes['subtotal'], 2),
    ]);

    HoldedInvoiceLine::query()->create([
        'holded_invoice_id' => $invoice->id,
        'position' => 1,
        'name' => $line,
        'units' => '1',
        'unit_price' => $attributes['subtotal'],
        'discount_pct' => '0',
        'subtotal' => $attributes['subtotal'],
        'tax_rate' => '21',
    ]);

    return $invoice;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();

    $this->admin = userWithRole('admin');
    $this->alfa = Client::factory()->create(['name' => 'Alfa Estudio']);
    $this->beta = Client::factory()->create(['name' => 'Beta Foods']);
    $this->project = Project::factory()->create(['client_id' => $this->alfa->id, 'code' => 'ALF-WEB', 'billing_type' => BillingType::FixedPrice]);
    Project::factory()->create(['client_id' => $this->beta->id, 'code' => 'BET-FE1', 'billing_type' => BillingType::MonthlyFee]);

    $this->old = listInvoice(['number' => 'F250010', 'client_id' => $this->alfa->id, 'issued_on' => '2025-11-10', 'due_on' => '2025-12-10', 'subtotal' => '1000.00', 'total' => '1210.00', 'pending_total' => '1210.00', 'collection_status' => CollectionStatus::Overdue], 'Desarrollo');
    $this->paid = listInvoice(['number' => 'F260001', 'client_id' => $this->alfa->id, 'issued_on' => '2026-01-10', 'due_on' => '2026-02-10', 'subtotal' => '1000.00', 'total' => '1210.00', 'paid_total' => '1210.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Paid], 'Desarrollo web');
    $this->late = listInvoice(['number' => 'F260002', 'client_id' => $this->beta->id, 'issued_on' => '2026-02-15', 'due_on' => '2026-03-01', 'subtotal' => '500.00', 'total' => '605.00', 'pending_total' => '605.00'], 'Fee MK y RRSS');
    $this->partial = listInvoice(['number' => 'F260003', 'client_id' => $this->beta->id, 'issued_on' => '2026-03-05', 'due_on' => '2026-04-05', 'subtotal' => '2000.00', 'total' => '2420.00', 'paid_total' => '1000.00', 'pending_total' => '1420.00', 'collection_status' => CollectionStatus::Partial], 'bolsadehoras');
    $this->cancelled = listInvoice(['number' => 'F260004', 'client_id' => $this->alfa->id, 'issued_on' => '2026-02-01', 'subtotal' => '300.00', 'total' => '363.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Cancelled], 'Desarrollo');
    $this->credit = listInvoice(['number' => 'CN260001', 'kind' => HoldedDocumentKind::CreditNote, 'client_id' => $this->beta->id, 'issued_on' => '2026-02-20', 'subtotal' => '-200.00', 'total' => '-242.00', 'pending_total' => '0.00', 'collection_status' => CollectionStatus::Paid, 'rectified_invoice_id' => $this->partial->id], 'Desarrollo');
    $this->draft = listInvoice(['client_id' => $this->alfa->id, 'issued_on' => '2026-03-29', 'subtotal' => '700.00', 'total' => '847.00', 'pending_total' => '0.00', 'is_draft' => true, 'collection_status' => CollectionStatus::Draft], 'Fee MK y RRSS');

    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $this->paid->id, 'project_id' => $this->project->id, 'method' => InvoiceLinkMethod::Manual, 'created_by' => $this->admin->id]);

    $this->numbers = fn (array $query = []): array => InvoiceList::fromQuery($query)->query()->get()
        ->map(fn (HoldedInvoice $invoice): string => $invoice->number ?? 'borrador')->all();
});

it('cuenta cada vista con su periodo por defecto: el año en curso en Todas y todo en las de trabajo', function () {
    $list = InvoiceList::fromQuery([]);

    expect($list->view)->toBe('todas')
        ->and($list->periodFor('todas'))->toBe(['key' => 'anio', 'from' => '2026-01-01', 'to' => '2026-12-31'])
        ->and($list->periodFor('vencidas'))->toBe(['key' => 'todo', 'from' => null, 'to' => null])
        ->and($list->viewCounts())->toBe([
            'todas' => 5,
            'por-cobrar' => 3,
            // La F260002 ya vence aunque Holded no se haya vuelto a leer; la de 2025 también cuenta.
            'vencidas' => 2,
            // Sin enlazar ni anular, también el borrador (D-388).
            'sin-proyecto' => 5,
            'borradores' => 1,
            'rectificativas' => 1,
        ])
        ->and(($this->numbers)())->toBe(['F260003', 'CN260001', 'F260002', 'F260004', 'F260001'])
        ->and(($this->numbers)(['vista' => 'vencidas']))->toBe(['F260002', 'F250010']);

    // Un periodo en la URL vale para todas las pestañas.
    expect(InvoiceList::fromQuery(['periodo' => 'todo'])->viewCounts()['todas'])->toBe(6)
        ->and(InvoiceList::fromQuery(['periodo' => 'anio'])->viewCounts()['vencidas'])->toBe(1)
        ->and(InvoiceList::fromQuery(['desde' => '2026-02-01', 'hasta' => '2026-02-28'])->periodFor('todas'))->toBe(['key' => 'rango', 'from' => '2026-02-01', 'to' => '2026-02-28']);
});

it('entiende los parámetros de antes como vistas o filtros (D-385)', function () {
    expect(InvoiceList::fromQuery(['enlace' => 'sin'])->view)->toBe('sin-proyecto')
        ->and(InvoiceList::fromQuery(['tipo' => 'credit_note'])->view)->toBe('rectificativas')
        ->and(InvoiceList::fromQuery(['estado' => 'overdue'])->view)->toBe('vencidas')
        ->and(InvoiceList::fromQuery(['estado' => 'draft'])->view)->toBe('borradores')
        ->and(($this->numbers)(['enlace' => 'con']))->toBe(['F260001'])
        ->and(($this->numbers)(['estado' => 'partial']))->toBe(['F260003'])
        ->and(($this->numbers)(['tipo' => 'invoice', 'periodo' => 'todo']))->toBe(['F260003', 'F260002', 'F260004', 'F260001', 'F250010'])
        // Lo que no se entiende se ignora.
        ->and(InvoiceList::fromQuery(['vista' => 'otra', 'orden' => 'x', 'cliente' => 'abc', 'desde' => '2026-13-45'])->toQuery())->toBe([]);
});

it('la barra de importes reparte lo de la vista en vencido, por vencer y cobrado, y cada tramo filtra', function () {
    expect(InvoiceList::fromQuery([])->collectionBar())->toBe([
        'vencido' => ['amount' => '605.00', 'count' => 1],
        'por-vencer' => ['amount' => '1420.00', 'count' => 1],
        'cobrado' => ['amount' => '2210.00', 'count' => 2],
    ])
        ->and(($this->numbers)(['cobro' => 'vencido']))->toBe(['F260002'])
        ->and(($this->numbers)(['cobro' => 'por-vencer']))->toBe(['F260003'])
        ->and(($this->numbers)(['cobro' => 'cobrado']))->toBe(['F260003', 'F260001'])
        ->and(InvoiceList::fromQuery(['vista' => 'vencidas'])->collectionBar()['vencido'])->toBe(['amount' => '1815.00', 'count' => 2]);
});

it('los totales al pie son los de lo filtrado, sin las anuladas; en Borradores, los borradores', function () {
    expect(InvoiceList::fromQuery([])->totals())->toBe(['count' => 4, 'subtotal' => '3300.00', 'total' => '3993.00', 'pending' => '2025.00'])
        ->and(InvoiceList::fromQuery(['vista' => 'borradores'])->totals())->toBe(['count' => 1, 'subtotal' => '700.00', 'total' => '847.00', 'pending' => '0.00'])
        ->and(InvoiceList::fromQuery(['cobro' => 'vencido'])->totals())->toBe(['count' => 1, 'subtotal' => '500.00', 'total' => '605.00', 'pending' => '605.00']);
});

it('ordena por cada columna con los vacíos al final', function () {
    expect(($this->numbers)(['orden' => 'pendiente']))->toBe(['F260003', 'F260002', 'CN260001', 'F260004', 'F260001'])
        ->and(($this->numbers)(['orden' => 'vencimiento', 'dir' => 'asc']))->toBe(['F260001', 'F260002', 'F260003', 'F260004', 'CN260001'])
        ->and(($this->numbers)(['orden' => 'numero']))->toBe(['CN260001', 'F260001', 'F260002', 'F260003', 'F260004'])
        ->and(($this->numbers)(['orden' => 'cliente', 'vista' => 'por-cobrar']))->toBe(['F250010', 'F260002', 'F260003'])
        ->and(($this->numbers)(['orden' => 'total', 'dir' => 'asc']))->toBe(['CN260001', 'F260004', 'F260002', 'F260001', 'F260003'])
        ->and(InvoiceList::fromQuery(['orden' => 'cliente'])->toQuery())->toBe(['orden' => 'cliente'])
        ->and(InvoiceList::fromQuery(['orden' => 'cliente', 'dir' => 'desc'])->toQuery())->toBe(['orden' => 'cliente', 'dir' => 'desc']);
});

it('busca por número, cliente, contacto o concepto y filtra por cliente y servicio', function () {
    expect(($this->numbers)(['buscar' => 'alfa']))->toBe(['F260004', 'F260001'])
        ->and(($this->numbers)(['buscar' => 'BOLSADE']))->toBe(['F260003'])
        ->and(($this->numbers)(['buscar' => 'f2500', 'periodo' => 'todo']))->toBe(['F250010'])
        ->and(($this->numbers)(['cliente' => (string) $this->beta->id]))->toBe(['F260003', 'CN260001', 'F260002'])
        ->and(($this->numbers)(['servicio' => ['fees'], 'periodo' => 'todo']))->toBe(['F260002'])
        ->and(($this->numbers)(['servicio' => ['fees'], 'vista' => 'borradores']))->toBe(['borrador']);
});

it('la página del listado lleva las vistas, la barra, los totales y las sugerencias sin consultas por fila', function () {
    $this->actingAs($this->admin)->get('/facturacion/facturas?orden=pendiente')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/invoices/index')
            ->where('filters.vista', 'todas')
            ->where('filters.orden', 'pendiente')
            ->where('list_query', ['orden' => 'pendiente'])
            ->where('period', ['key' => 'anio', 'from' => '2026-01-01', 'to' => '2026-12-31'])
            ->where('views.vencidas', 2)
            ->where('bar.vencido.amount', '605.00')
            ->where('totals.count', 4)
            ->where('today', '2026-03-20')
            ->has('invoices.data', 5)
            ->where('invoices.data.0.number', 'F260003')
            ->where('invoices.meta.total', 5)
            // F260002 (Fee MK y RRSS de Beta) sugiere su fee mensual.
            ->where('invoices.data.1.suggestion.project.code', 'BET-FE1')
            ->where('invoices.data.4.suggestion', null)
        );
});

it('la ficha trae la anterior y la siguiente del listado del que vienes y vuelve con sus filtros (D-408)', function () {
    $this->actingAs($this->admin)->get("/facturacion/facturas/{$this->late->id}?orden=pendiente")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/invoices/show')
            ->where('list.back', '/facturacion/facturas?orden=pendiente')
            ->where('list.previous', "/facturacion/facturas/{$this->partial->id}?orden=pendiente")
            ->where('list.next', "/facturacion/facturas/{$this->credit->id}?orden=pendiente")
            ->where('list.position', 2)
            ->where('list.total', 5)
            ->where('holded_url', 'https://app.holded.com/sales/revenue')
            ->where('today', '2026-03-20')
            // Primero los proyectos de su cliente, después el resto (FIC-3).
            ->where('projects.0.code', 'BET-FE1')
            ->where('projects.0.own_client', true)
            ->where('projects.1.code', 'ALF-WEB')
            ->where('projects.1.own_client', false)
        );

    // Fuera de la lista (una de 2025 con el listado por defecto): sin anterior ni siguiente.
    $this->actingAs($this->admin)->get("/facturacion/facturas/{$this->old->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('list.back', '/facturacion/facturas')
            ->where('list.previous', null)
            ->where('list.next', null)
            ->where('list.position', null)
        );
});

it('la línea de tiempo de la ficha sabe quién enlazó a mano y cuándo', function () {
    $this->actingAs($this->admin)->get("/facturacion/facturas/{$this->paid->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.links.0.method', 'manual')
            ->where('invoice.links.0.created_by', $this->admin->name)
            ->where('invoice.links.0.created_at', fn (?string $value) => $value !== null)
        );
});

it('la navegación comparte «Por revisar» y la última lectura de Holded solo con view-billing, y se refresca al casar (D-409)', function () {
    Cache::flush();
    $contact = HoldedContact::query()->create(['holded_id' => 'c-1', 'name' => 'Gamma, S.L.']);
    HoldedContact::query()->create(['holded_id' => 'c-2', 'name' => 'Delta', 'client_id' => $this->alfa->id, 'match_method' => HoldedContact::MATCH_APPROX]);
    HoldedContact::query()->create(['holded_id' => 'c-3', 'name' => 'Épsilon', 'ignored_at' => now()]);
    HoldedSyncRun::query()->create(['trigger' => 'schedule', 'status' => HoldedSyncRun::OK, 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3)->addMinute()]);
    HoldedSyncRun::query()->create(['trigger' => 'manual', 'status' => HoldedSyncRun::FAILED, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()->addMinute()]);

    $this->actingAs($this->admin)->get('/facturacion/facturas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('billingNav.review', 2)
            ->where('billingNav.sync.status', 'failed')
            ->where('billingNav.sync.last_ok_at', now()->subHours(3)->addMinute()->utc()->toIso8601ZuluString())
        );

    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'assign', 'client_id' => $this->beta->id])->assertRedirect();
    expect(Cache::has(BillingNav::CACHE_KEY))->toBeFalse();

    $this->actingAs($this->admin)->get('/facturacion/facturas')
        ->assertInertia(fn (Assert $page) => $page->where('billingNav.review', 1));

    $manager = userWithRole('department_manager');
    $this->actingAs($manager)->get('/facturacion/vendido-frente-a-real')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billingNav', null));
});
