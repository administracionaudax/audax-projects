<?php

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Issuing\InvoiceDocumentSettings;
use App\Enums\BillingService;
use App\Enums\Permission;
use App\Models\CatalogService;
use App\Models\NumberingCounter;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajustes de la emisión (PLAN-EMISION §6.2; D-419, D-423, D-425 y D-426): series y su primer número,
| impuestos, servicios (importados de las líneas de Holded), formas de pago y la plantilla del PDF.
| Verlos: use-invoicing; cambiarlos: manage-billing.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
});

it('vienen de partida las series F y CN desde el 1/1/2027, la de pruebas, los impuestos y las formas de pago', function () {
    expect(NumberingSeries::query()->orderBy('id')->get()->map(fn ($series) => [$series->code, $series->format, $series->starts_on?->toDateString(), $series->kind->value])->all())->toBe([
        ['CN', 'CN[YY]####', '2027-01-01', 'regular'],
        ['F', 'F[YY]####', '2027-01-01', 'regular'],
        ['PRUCN', 'PRUCN[YY]####', null, 'test'],
        ['PRU', 'PRU[YY]####', null, 'test'],
    ])
        ->and(TaxRate::query()->orderBy('id')->pluck('key')->all())->toBe(['iva_21', 'iva_10', 'iva_4', 'iva_0', 'intra_eu', 'export', 'exempt', 'reverse_charge', 'irpf_15', 'irpf_7'])
        ->and(PaymentMethod::query()->where('is_default', true)->value('name'))->toBe('Transferencia bancaria');

    $this->actingAs($this->admin)->get('/facturacion/ajustes?apartado=series')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('section', 'series')
            ->where('invoicing.can_manage', true)
            ->where('invoicing.series.1.code', 'F')
            ->where('invoicing.series.1.counters.0.next_number', 'F260001')
            ->where('invoicing.document.validated', false));
});

it('quien prepara ve los ajustes pero no los cambia; sin la emisión no salen', function () {
    $finance = userWithRole('employee');
    $finance->givePermissionTo(Permission::ViewFinancials->value);

    $this->actingAs($finance)->get('/facturacion/ajustes?apartado=impuestos')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('invoicing.can_manage', false));
    $this->actingAs($finance)->post('/facturacion/ajustes/emision/impuestos', ['kind' => 'vat', 'name' => 'X', 'rate' => 5, 'operation_type' => 'S1'])->assertForbidden();

    enableInvoicing(false);
    $this->actingAs($this->admin)->get('/facturacion/ajustes')->assertOk()->assertInertia(fn (Assert $page) => $page->where('invoicing', null));
});

it('cambia una serie y fija el primer número del año solo hacia arriba y antes de emitir', function () {
    $series = invoicingSeries('F');

    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}", ['name' => 'Facturas', 'format' => 'F[AA]##', 'starts_on' => '2027-01-01', 'is_default' => true, 'archived' => false])
        ->assertSessionHasErrors('format');
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}", ['name' => 'Facturas', 'format' => 'F[YY]#####', 'starts_on' => '2027-01-01', 'is_default' => true, 'archived' => false])
        ->assertSessionHasNoErrors();
    expect($series->refresh()->formatNumber(2027, 1))->toBe('F2700001');

    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}/contador", ['year' => 2027, 'first_number' => 121])->assertSessionHasNoErrors();
    expect(NumberingCounter::query()->where('series_id', $series->id)->where('year', 2027)->first()?->only(['first_number', 'last_number']))->toBe(['first_number' => 121, 'last_number' => 120]);

    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}/contador", ['year' => 2027, 'first_number' => 50])->assertSessionHasErrors('first_number');

    // La primera de 2027 sigue la numeración fijada.
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    expect(invoicingIssue($this->admin, invoicingClient())->full_number)->toBe('F2700121');
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}/contador", ['year' => 2027, 'first_number' => 500])->assertSessionHasErrors('first_number');
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/series/{$series->id}", ['name' => 'Facturas', 'format' => 'F[YY]####', 'starts_on' => '2027-01-01', 'is_default' => true, 'archived' => false])
        ->assertSessionHasErrors('format');
});

it('crea y cambia impuestos; el tipo de uno usado no se cambia', function () {
    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/impuestos', ['kind' => 'vat', 'name' => 'IVA 5 %', 'rate' => '5', 'operation_type' => 'S1', 'is_default' => false])->assertSessionHasNoErrors();
    $tax = TaxRate::query()->where('name', 'IVA 5 %')->firstOrFail();
    expect((string) $tax->rate)->toBe('5.00');

    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    invoicingIssue($this->admin, invoicingClient(), [invoicingLine('1', '100', $tax->key)]);
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/impuestos/{$tax->id}", ['kind' => 'vat', 'name' => 'IVA 5 %', 'rate' => '6', 'operation_type' => 'S1'])->assertSessionHasErrors('rate');
    $this->actingAs($this->admin)->put("/facturacion/ajustes/emision/impuestos/{$tax->id}", ['kind' => 'vat', 'name' => 'IVA superreducido', 'rate' => '5', 'operation_type' => 'S1', 'legal_mention' => 'Tipo reducido.', 'archived' => true])->assertSessionHasNoErrors();
    expect($tax->refresh()->name)->toBe('IVA superreducido')->and($tax->archived_at)->not->toBeNull();
});

it('importa los servicios del catálogo desde las líneas de las facturas de Holded, sin repetir', function () {
    app()->instance(HoldedApi::class, holdedFake([
        'contacts' => [holdedContact('c1', 'Cliente', 'B1')],
        'invoices' => [
            holdedInvoice('h1', 'F260001', 'c1', '2026-01-10', '1200.00', ['items' => [['name' => 'bolsadehoras', 'code' => 'BDH', 'units' => 20, 'price' => '60', 'subtotal' => '1200.00', 'taxes' => ['s_iva_21']]]]),
            holdedInvoice('h2', 'F260002', 'c1', '2026-02-10', '650.00', ['items' => [['name' => 'Desarrollo', 'code' => 'DES', 'units' => 10, 'price' => '65', 'subtotal' => '650.00', 'taxes' => ['s_iva_21']]]]),
            holdedInvoice('h3', 'F260003', 'c1', '2026-03-10', '900.00', ['items' => [['name' => 'Fee Producto digital', 'code' => 'F_UX', 'units' => 1, 'price' => '900', 'subtotal' => '900.00', 'taxes' => ['s_iva_21']]]]),
        ],
    ]));
    syncHolded();

    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/servicios/importar')->assertSessionHasNoErrors();
    $services = CatalogService::query()->orderBy('code')->get();

    expect($services->map(fn ($service) => [$service->code, $service->name, $service->unit->value, (string) $service->unit_price])->all())->toBe([
        ['BDH', 'bolsadehoras', 'hour', '60.0000'],
        ['DES', 'Desarrollo', 'hour', '65.0000'],
        ['F_UX', 'Fee Producto digital', 'month', '900.0000'],
    ])->and($services->every(fn ($service) => $service->tax_rate_id === invoicingTax('iva_21')->id))->toBeTrue();

    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/servicios/importar');
    expect(CatalogService::query()->count())->toBe(3);

    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/servicios', ['code' => 'seo', 'name' => 'SEO', 'unit' => 'hour', 'unit_price' => '55', 'tax_rate_id' => invoicingTax('iva_21')->id])->assertSessionHasNoErrors();
    expect(CatalogService::query()->where('code', 'SEO')->firstOrFail()->category)->toBe(BillingService::Seo);
});

it('guarda las formas de pago y la plantilla del PDF con su logo', function () {
    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/formas-de-pago', ['name' => 'Tarjeta', 'due_days' => 0, 'document_text' => 'Pago con tarjeta', 'is_default' => true])->assertSessionHasNoErrors();
    expect(PaymentMethod::query()->where('is_default', true)->value('name'))->toBe('Tarjeta');

    $this->actingAs($this->admin)->put('/facturacion/ajustes/emision/documento', ['footer' => 'Gracias.', 'legal_text' => 'RGPD.', 'validated' => true])->assertSessionHasNoErrors();
    expect(InvoiceDocumentSettings::get())->toMatchArray(['footer' => 'Gracias.', 'legal_text' => 'RGPD.', 'validated' => true]);

    $this->actingAs($this->admin)->post('/facturacion/ajustes/emision/documento/logo', ['logo' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHasNoErrors();
    expect(InvoiceDocumentSettings::get()['logo'])->toMatchArray(['path' => 'invoicing/logo.png', 'width' => 400, 'height' => 120])
        ->and(InvoiceDocumentSettings::logoHtml('Audax'))->toContain('data:image/png;base64,');

    $this->actingAs($this->admin)->delete('/facturacion/ajustes/emision/documento/logo');
    expect(InvoiceDocumentSettings::get()['logo'])->toBeNull()
        ->and(Storage::disk('local')->exists('invoicing/logo.png'))->toBeFalse();
});
