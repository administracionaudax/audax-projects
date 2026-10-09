<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\ReviewInbox;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «No necesita proyecto» (D-431): una factura sin proyecto ni bolsa que no lo necesita (gastos
| repercutidos, una factura suelta) se marca con un motivo opcional y deja de contar en «Sin
| proyecto», en el contador de la barra lateral, en «Requiere atención» y en la cobertura; se ve con
| el filtro `enlace=no-necesita`, se deshace («Necesita proyecto» o «Deshacer» en la bandeja) y la
| sincronización con Holded nunca la borra.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->client = Client::factory()->create(['name' => 'Acme']);
    HoldedContact::query()->create(['holded_id' => 'c-acme', 'name' => 'ACME, S.L.', 'client_id' => $this->client->id, 'match_method' => HoldedContact::MATCH_MANUAL]);

    $this->make = function (string $number, string $subtotal, array $extra = []): HoldedInvoice {
        return HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'number_normalized' => $number,
            'holded_contact_id' => 'c-acme', 'client_id' => $this->client->id, 'issued_on' => '2026-03-02',
            'subtotal' => $subtotal, 'tax_total' => '0.00', 'total' => $subtotal, 'paid_total' => '0.00', 'pending_total' => $subtotal,
            'collection_status' => CollectionStatus::Unpaid, ...$extra,
        ]);
    };
    // Dos sin proyecto (una de gastos repercutidos) y una enlazada.
    $this->expenses = ($this->make)('F260050', '300.00');
    $this->loose = ($this->make)('F260051', '700.00');
    $linked = ($this->make)('F260052', '1000.00');
    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $linked->id, 'project_id' => Project::factory()->create(['client_id' => $this->client->id])->id, 'method' => InvoiceLinkMethod::Manual]);

    $this->mark = fn (HoldedInvoice $invoice, ?string $note = null) => $this->actingAs($this->admin)
        ->from('/facturacion/por-revisar?tipo=facturas')
        ->post("/facturacion/facturas/{$invoice->id}/sin-proyecto", ['note' => $note]);
});

it('marcarla la saca de «Sin proyecto», del contador, de «Requiere atención» y de la cobertura', function () {
    expect(ReviewInbox::counts()['facturas'])->toBe(2)
        ->and(ReviewInbox::coverage())->toMatchArray(['invoices_linked' => 1, 'invoices_total' => 3]);

    ($this->mark)($this->expenses, '  Gastos repercutidos de la feria  ')->assertRedirect('/facturacion/por-revisar?tipo=facturas');

    $invoice = $this->expenses->fresh();
    expect($invoice->no_project_needed_at)->not->toBeNull()
        ->and($invoice->no_project_needed_by)->toBe($this->admin->id)
        ->and($invoice->no_project_note)->toBe('Gastos repercutidos de la feria')
        ->and(ReviewInbox::counts()['facturas'])->toBe(1)
        ->and(ReviewInbox::coverage())->toMatchArray(['invoices_linked' => 1, 'invoices_total' => 2]);

    // La bandeja: solo la otra, con el número de las marcadas para verlas.
    $this->actingAs($this->admin)->get('/facturacion/por-revisar?tipo=facturas')
        ->assertInertia(fn (Assert $page) => $page->where('counts.facturas', 1)
            ->where('no_project_count', 1)
            ->where('invoices', fn ($rows) => collect($rows)->pluck('number')->all() === ['F260051'])
            ->where('billingNav.review', 1));

    // El Resumen: «Requiere atención» sin ella.
    $summary = app(BillingSummary::class)->summary($this->admin, BillingSummary::period([]));
    expect($summary['attention']['unlinked'])->toBe(['count' => 1, 'amount' => '700.00']);

    // El listado: fuera de «Sin proyecto», con el filtro `enlace=no-necesita`.
    $this->actingAs($this->admin)->get('/facturacion/facturas?vista=sin-proyecto')
        ->assertInertia(fn (Assert $page) => $page->where('views.sin-proyecto', 1)
            ->where('invoices.data', fn ($rows) => collect($rows)->pluck('number')->all() === ['F260051']));
    $this->actingAs($this->admin)->get('/facturacion/facturas?enlace=no-necesita&periodo=todo')
        ->assertInertia(fn (Assert $page) => $page->where('filters.enlace', 'no-necesita')
            ->where('invoices.data', fn ($rows) => collect($rows)->pluck('number')->all() === ['F260050']
                && collect($rows)->first()['no_project_needed'] === true));

    // La ficha: quién, cuándo y por qué, y sin sugerencias.
    $this->actingAs($this->admin)->get("/facturacion/facturas/{$this->expenses->id}")
        ->assertInertia(fn (Assert $page) => $page->where('invoice.no_project.by', $this->admin->name)
            ->where('invoice.no_project.note', 'Gastos repercutidos de la feria')
            ->where('suggestions', []));
});

it('se deshace con «Necesita proyecto» y con «Deshacer» en la bandeja', function () {
    ($this->mark)($this->expenses);
    expect($this->expenses->fresh()->no_project_note)->toBeNull();

    // «Deshacer» de la bandeja (D-414).
    $this->actingAs($this->admin)->get('/facturacion/por-revisar?tipo=facturas')
        ->assertInertia(fn (Assert $page) => $page->where('undo.count', 1)->where('undo.message', fn (string $message) => str_contains($message, 'F260050')));
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect();
    expect($this->expenses->fresh()->no_project_needed_at)->toBeNull()
        ->and(ReviewInbox::counts()['facturas'])->toBe(2);

    // «Necesita proyecto», desde la ficha.
    ($this->mark)($this->loose, 'Factura suelta');
    $this->actingAs($this->admin)->delete("/facturacion/facturas/{$this->loose->id}/sin-proyecto")->assertRedirect();
    expect($this->loose->fresh()->only(['no_project_needed_at', 'no_project_needed_by', 'no_project_note']))
        ->toBe(['no_project_needed_at' => null, 'no_project_needed_by' => null, 'no_project_note' => null])
        ->and(ReviewInbox::counts()['facturas'])->toBe(2);
});

it('no se marca una anulada ni una enlazada, y el motivo tiene un máximo', function () {
    $cancelled = ($this->make)('F260060', '50.00', ['collection_status' => CollectionStatus::Cancelled]);
    ($this->mark)($cancelled)->assertStatus(422);

    $linked = HoldedInvoice::query()->where('number', 'F260052')->sole();
    ($this->mark)($linked)->assertStatus(422);

    ($this->mark)($this->loose, str_repeat('a', 501))->assertSessionHasErrors('note');
    expect($this->loose->fresh()->no_project_needed_at)->toBeNull();
});

it('la sincronización con Holded nunca borra la marca', function () {
    ($this->mark)($this->expenses, 'Gastos repercutidos');
    $marked = $this->expenses->fresh();

    // La misma factura cambia en Holded (otro importe y cobrada): se reescribe, pero la marca se queda.
    syncHolded(holdedFake([
        'contacts' => [holdedContact('c-acme', 'ACME, S.L.')],
        'invoices' => [holdedInvoice('h-F260050', 'F260050', 'c-acme', '2026-03-02', '320.00', ['payments_total' => '387.20', 'payments_pending' => '0.00', 'status' => 'paid'])],
    ]));

    $after = $this->expenses->fresh();
    expect((string) $after->subtotal)->toBe('320.00')
        ->and($after->no_project_needed_at?->toIso8601String())->toBe($marked->no_project_needed_at?->toIso8601String())
        ->and($after->no_project_needed_by)->toBe($this->admin->id)
        ->and($after->no_project_note)->toBe('Gastos repercutidos');
});

it('quién puede marcar: view-billing (como enlazar)', function () {
    $excluded = userWithRole('admin');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$excluded->id]])->assertRedirect();
    $actors = billingActors($excluded);

    //            admin finan. resp. empl. colab. cliente excluido
    $expected = [302, 302, 403, 403, 403, 302, 404];
    foreach (array_values($actors) as $index => $actor) {
        $status = $this->actingAs($actor)->post("/facturacion/facturas/{$this->loose->id}/sin-proyecto", ['note' => 'x'])->getStatusCode();
        expect($status)->toBe($expected[$index], 'como '.array_keys($actors)[$index]);
        if ($index < 2) {
            $this->loose->fresh()?->clearNoProjectNeeded();
        }
    }
    expect($this->loose->fresh()->no_project_needed_at)->toBeNull();

    foreach (array_values($actors) as $index => $actor) {
        $status = $this->actingAs($actor)->delete("/facturacion/facturas/{$this->loose->id}/sin-proyecto")->getStatusCode();
        expect($status)->toBe($expected[$index], 'deshacer como '.array_keys($actors)[$index]);
    }
});
