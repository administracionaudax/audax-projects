<?php

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\InvoiceList;
use App\Enums\SalesDocumentStatus;
use App\Models\CatalogService;
use App\Models\HoldedInvoice;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| El editor, la ficha y las acciones por HTTP (PLAN-EMISION §6.2; D-428): nueva factura (también
| desde el cliente y el proyecto), guardar borrador, vista previa sin guardar, emitir, duplicar
| (también una de Holded), anular, rectificar, anular el registro y lo no fiscal.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    $this->project = Project::factory()->create(['client_id' => $this->client->id]);
    $this->service = CatalogService::query()->create(['code' => 'DES', 'name' => 'Desarrollo', 'unit' => 'hour', 'unit_price' => '60', 'tax_rate_id' => invoicingTax('iva_21')->id, 'category' => 'desarrollo']);
    $this->payload = fn (array $overrides = []): array => [
        'client_id' => $this->client->id,
        'series_id' => invoicingSeries('F')->id,
        'issue_date' => '2027-01-04',
        'operation_date' => null,
        'due_date' => '2027-02-03',
        'payment_method_id' => null,
        'withholding_rate_id' => null,
        'body' => 'Gracias.',
        'internal_note' => 'Revisar horas',
        'customer_reference' => null,
        'project_id' => $this->project->id,
        'hour_bank_id' => null,
        'lines' => [
            ['kind' => 'item', 'service_id' => $this->service->id, 'description' => 'Sprint 1', 'quantity' => '8', 'unit' => 'hour', 'unit_price' => '60', 'discount_pct' => '0', 'tax_rate_id' => invoicingTax('iva_21')->id],
            ['kind' => 'text', 'description' => 'Incluye las reuniones de seguimiento.'],
        ],
        ...$overrides,
    ];
});

it('«Nueva factura» abre el editor con el cliente y el proyecto de donde viene', function () {
    $this->actingAs($this->admin)->get("/facturacion/facturas/nueva?proyecto={$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/documents/edit')
            ->where('document', null)
            ->where('defaults.client_id', $this->client->id)
            ->where('defaults.project_id', $this->project->id)
            ->where('defaults.series_id', invoicingSeries('F')->id)
            ->where('options.clients', fn ($clients) => collect($clients)->firstWhere('id', $this->client->id)['missing'] === [])
            ->where('options.services.0.code', 'DES')
            ->has('preview_token'));
});

it('guarda el borrador con sus líneas y su proyecto, y lo vuelve a editar', function () {
    $this->actingAs($this->admin)->post('/facturacion/documentos', ($this->payload)())->assertRedirect();

    $draft = SalesDocument::query()->latest('id')->firstOrFail();
    expect($draft->status)->toBe(SalesDocumentStatus::Draft)
        ->and((string) $draft->subtotal)->toBe('480.00')
        ->and($draft->lines()->pluck('kind')->all())->toBe(['item', 'text'])
        ->and($draft->lines()->first()->service_code)->toBe('DES')
        ->and(SalesDocumentLink::query()->where('sales_document_id', $draft->id)->value('project_id'))->toBe($this->project->id);

    $line = $draft->lines()->first();
    $this->actingAs($this->admin)->put("/facturacion/documentos/{$draft->id}", ($this->payload)([
        'project_id' => null,
        'lines' => [['id' => $line->id, 'kind' => 'item', 'service_id' => $this->service->id, 'description' => 'Sprint 1', 'quantity' => '10', 'unit' => 'hour', 'unit_price' => '60', 'discount_pct' => '10', 'tax_rate_id' => invoicingTax('iva_21')->id]],
    ]))->assertRedirect("/facturacion/documentos/{$draft->id}");

    expect((string) $draft->refresh()->subtotal)->toBe('540.00')
        ->and(SalesDocumentLine::query()->where('sales_document_id', $draft->id)->count())->toBe(1)
        ->and($draft->lines()->first()->id)->toBe($line->id)
        ->and(SalesDocumentLink::query()->where('sales_document_id', $draft->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$draft->id}/editar")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('document.lines.0.quantity', '10')->where('document.lines.0.discount_pct', '10'));
});

it('valida el borrador: cliente, al menos una línea y el concepto de cada línea', function () {
    $this->actingAs($this->admin)->post('/facturacion/documentos', ($this->payload)(['client_id' => null, 'lines' => []]))
        ->assertSessionHasErrors(['client_id', 'lines']);
    $this->actingAs($this->admin)->post('/facturacion/documentos', ($this->payload)(['lines' => [['kind' => 'item', 'description' => '', 'quantity' => '1', 'unit_price' => '1']]]))
        ->assertSessionHasErrors(['lines.0.description']);
});

it('«Guardar y emitir» lleva a la ficha con el diálogo de emitir abierto, y emitir le da su número', function () {
    $this->actingAs($this->admin)->post('/facturacion/documentos', [...($this->payload)(), 'after' => 'emitir'])
        ->assertRedirect();
    $draft = SalesDocument::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$draft->id}?emitir=1")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/documents/show')
            ->where('open_issue', true)
            ->where('issue.number', 'F270001')
            ->where('issue.problems', []));

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$draft->id}/emitir")
        ->assertRedirect("/facturacion/documentos/{$draft->id}")
        ->assertSessionHasNoErrors();
    expect($draft->refresh()->full_number)->toBe('F270001');

    // Una emitida ya no se edita: el editor lleva a su ficha y guardar falla.
    $this->actingAs($this->admin)->get("/facturacion/documentos/{$draft->id}/editar")->assertRedirect("/facturacion/documentos/{$draft->id}");
    $this->actingAs($this->admin)->put("/facturacion/documentos/{$draft->id}", ($this->payload)())->assertSessionHasErrors('document');
    $this->actingAs($this->admin)->delete("/facturacion/documentos/{$draft->id}")->assertForbidden();
});

it('la vista previa del editor no guarda nada', function () {
    $count = SalesDocument::query()->count();

    $response = $this->actingAs($this->admin)->post('/facturacion/documentos/vista-previa', ($this->payload)())->assertOk();

    expect($response->getContent())->toContain('Borrador')->toContain('Sprint 1')->toContain('480,00 €')
        ->and(SalesDocument::query()->count())->toBe($count);
});

it('duplica una propia y una de Holded como borradores, y borra un borrador', function () {
    $issued = invoicingIssue($this->admin, $this->client);
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$issued->id}/duplicar")->assertRedirect();
    $copy = SalesDocument::query()->latest('id')->firstOrFail();
    expect($copy->status)->toBe(SalesDocumentStatus::Draft)
        ->and($copy->source_document_id)->toBe($issued->id)
        ->and((string) $copy->subtotal)->toBe((string) $issued->subtotal);

    app()->instance(HoldedApi::class, holdedFake([
        'contacts' => [holdedContact('c1', $this->client->name, 'B87654321')],
        'invoices' => [holdedInvoice('h1', 'F260100', 'c1', '2026-12-01', '500.00', ['items' => [['name' => 'Desarrollo', 'code' => 'DES', 'units' => 5, 'price' => '100', 'subtotal' => '500.00', 'taxes' => ['s_iva_21']]]])],
    ]));
    syncHolded();
    $holded = HoldedInvoice::query()->firstOrFail();
    $this->actingAs($this->admin)->post("/facturacion/facturas/{$holded->id}/duplicar")->assertRedirect();
    $fromHolded = SalesDocument::query()->latest('id')->firstOrFail();
    expect($fromHolded->source_holded_invoice_id)->toBe($holded->id)
        ->and((string) $fromHolded->subtotal)->toBe('500.00');

    $this->actingAs($this->admin)->delete("/facturacion/documentos/{$fromHolded->id}")->assertRedirect('/facturacion/facturas?vista=borradores');
    expect(SalesDocument::query()->find($fromHolded->id))->toBeNull();
});

it('anula, rectifica y anula el registro desde la ficha, y cambia lo no fiscal de una emitida', function () {
    $first = invoicingIssue($this->admin, $this->client);
    $second = invoicingIssue($this->admin, $this->client);
    $third = invoicingIssue($this->admin, $this->client);

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$first->id}/anular", ['reason' => 'abc'])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$first->id}/anular", ['reason' => 'Error en el cliente', 'code' => 'R1'])->assertRedirect();
    expect($first->refresh()->status)->toBe(SalesDocumentStatus::Cancelled);

    $line = SalesDocumentLine::query()->where('sales_document_id', $second->id)->firstOrFail();
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$second->id}/rectificar", ['reason' => 'Una hora menos', 'lines' => [['line_id' => $line->id, 'quantity' => '9', 'unit_price' => '60']]])
        ->assertRedirect();
    expect(SalesDocument::query()->where('rectified_document_id', $second->id)->value('subtotal'))->toBe('-60.00');

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$third->id}/anular-registro", ['reason' => 'Emitida por error'])->assertRedirect();
    expect($third->refresh()->status)->toBe(SalesDocumentStatus::Voided);

    $this->actingAs($this->admin)->put("/facturacion/documentos/{$second->id}/no-fiscal", ['internal_note' => 'Llamar a contabilidad', 'links' => [['project_id' => $this->project->id, 'hour_bank_id' => null]]])
        ->assertRedirect();
    expect($second->refresh()->internal_note)->toBe('Llamar a contabilidad')
        ->and(SalesDocumentLink::query()->where('sales_document_id', $second->id)->value('project_id'))->toBe($this->project->id);

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$second->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.number', 'F270002')
            ->where('document.rectifications.0.number', 'CN270002')
            ->where('document.records.0.kind', 'alta')
            ->where('document.links.0.project.id', $this->project->id)
            ->where('actions', ['view', 'duplicate', 'download_pdf', 'edit_non_fiscal', 'cancel', 'rectify', 'void', 'view_record'])
            ->where('void_problems', ['La factura tiene rectificativas: no se puede anular su registro.']));
});

it('una propia sin proyecto se marca «No necesita proyecto» y deja de salir en «Sin proyecto» (D-431)', function () {
    $invoice = invoicingIssue($this->admin, $this->client);
    $count = fn (): int => InvoiceList::fromQuery(['vista' => 'sin-proyecto'])->viewCounts()['sin-proyecto'];
    expect($count())->toBe(1);

    $this->actingAs($this->admin)->post("/facturacion/documentos/{$invoice->id}/sin-proyecto", ['note' => 'Gastos repercutidos'])->assertRedirect();
    expect($invoice->refresh()->noProjectNeeded())->toBeTrue()
        ->and($invoice->no_project_note)->toBe('Gastos repercutidos')
        ->and($count())->toBe(0)
        ->and(InvoiceList::fromQuery(['enlace' => 'no-necesita', 'periodo' => 'todo'])->query()->pluck('number')->all())->toBe(['F270001']);

    $this->actingAs($this->admin)->get("/facturacion/documentos/{$invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->where('document.no_project.note', 'Gastos repercutidos'));

    $this->actingAs($this->admin)->delete("/facturacion/documentos/{$invoice->id}/sin-proyecto")->assertRedirect();
    expect($invoice->refresh()->noProjectNeeded())->toBeFalse();
});
