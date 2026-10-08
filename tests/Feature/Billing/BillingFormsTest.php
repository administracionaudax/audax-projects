<?php

use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Escrituras de Facturación (Fase 12, F1): ficha fiscal del cliente, datos del emisor, contactos de
| Holded, enlaces a mano, el tipo «Fee mensual» del proyecto y su conversión de propuesta
| (app:convert-monthly-fees). Y la ficha de la bolsa con su «Vendido frente a real» diferido.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->client = Client::factory()->create(['name' => 'Acme', 'tax_id' => null]);
});

it('guarda la ficha fiscal del cliente y normaliza NIF, NIF-IVA y emails', function () {
    $this->actingAs($this->admin)->put("/clientes/{$this->client->id}/datos-fiscales", [
        'tax_id' => 'b-12.345.678', 'legal_name' => 'Acme Soluciones, S.L.', 'eu_vat_number' => 'es b12345678',
        'address' => 'Calle Mayor, 1', 'postal_code' => '46001', 'city' => 'València', 'province' => 'Valencia', 'country_code' => 'es',
        'tax_regime' => 'intra_eu', 'payment_method' => 'transfer', 'payment_days' => 30, 'payment_day' => 10, 'language' => 'en',
        'billing_emails' => ['Facturas@Acme.example', 'facturas@acme.example', 'otra@acme.example'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $profile = ClientBillingProfile::query()->where('client_id', $this->client->id)->firstOrFail();
    expect($this->client->fresh()?->tax_id)->toBe('B12345678')
        ->and($profile->eu_vat_number)->toBe('ESB12345678')
        ->and($profile->country_code)->toBe('ES')
        ->and($profile->tax_regime->value)->toBe('intra_eu')
        ->and($profile->billing_emails)->toBe(['facturas@acme.example', 'otra@acme.example'])
        ->and($profile->isComplete())->toBeTrue();

    $this->actingAs($this->admin)->put("/clientes/{$this->client->id}/datos-fiscales", [
        'country_code' => 'ESP', 'tax_regime' => 'nada', 'language' => 'es', 'billing_emails' => ['no-es-un-email'], 'payment_day' => 40,
    ])->assertSessionHasErrors(['country_code', 'tax_regime', 'billing_emails.0', 'payment_day']);

    $this->actingAs($this->admin)->get("/clientes/{$this->client->id}/facturacion")
        ->assertInertia(fn (Assert $page) => $page->component('clients/billing')->where('profile.legal_name', 'Acme Soluciones, S.L.')->where('profile.complete', true));
});

it('guarda los datos del emisor en el ajuste billing_issuer y lo deja en la auditoría', function () {
    $this->actingAs($this->admin)->put('/facturacion/ajustes', [
        'legal_name' => 'Audax Studio, S.L.', 'tax_id' => 'b 00.000.000', 'country_code' => 'es', 'iban' => 'es00 0000 0000 0000 0000 0000',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('billing_issuer'))->toMatchArray(['legal_name' => 'Audax Studio, S.L.', 'tax_id' => 'B00000000', 'country_code' => 'ES', 'iban' => 'ES0000000000000000000000', 'city' => null])
        ->and(Activity::query()->where('log_name', 'settings')->exists())->toBeTrue();

    $this->actingAs($this->admin)->put('/facturacion/ajustes', ['country_code' => 'ES', 'iban' => 'no', 'email' => 'mal'])
        ->assertSessionHasErrors(['iban', 'email']);
});

it('resuelve un contacto: asignar mueve sus facturas al cliente; descartar y volver a casar solo', function () {
    $contact = HoldedContact::query()->create(['holded_id' => 'c9', 'name' => 'Acme, S.L.', 'tax_id' => 'B99999999', 'tax_id_normalized' => 'B99999999', 'address' => ['city' => 'Valencia']]);
    $invoice = HoldedInvoice::query()->create(['holded_id' => 'x1', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260001', 'holded_contact_id' => 'c9', 'issued_on' => '2026-03-01', 'collection_status' => CollectionStatus::Unpaid]);
    $other = Client::factory()->create(['name' => 'Otro', 'tax_id' => null]);

    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'assign', 'client_id' => $other->id])->assertRedirect();
    expect($contact->fresh()?->client_id)->toBe($other->id)
        ->and($contact->fresh()?->match_method)->toBe('manual')
        ->and($invoice->fresh()?->client_id)->toBe($other->id)
        // Rellena lo que le falta al cliente.
        ->and($other->fresh()?->tax_id)->toBe('B99999999');

    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'ignore'])->assertRedirect();
    expect($contact->fresh()?->ignored_at)->not->toBeNull()->and($invoice->fresh()?->client_id)->toBeNull();

    // Volver a casarlo solo: ahora por el NIF que Otro tomó de Holded (antes que por el nombre, «Acme»).
    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'auto'])->assertRedirect();
    expect($contact->fresh()?->client_id)->toBe($other->id)->and($contact->fresh()?->match_method)->toBe('tax_id');
    $other->refresh()->update(['tax_id' => null]);
    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'auto'])->assertRedirect();
    expect($contact->fresh()?->client_id)->toBe($this->client->id)->and($contact->fresh()?->match_method)->toBe('name');

    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'assign'])->assertSessionHasErrors('client_id');
});

it('enlaza a mano con un proyecto y su bolsa, no con una bolsa de otro proyecto; quita solo los manuales', function () {
    $project = Project::factory()->hourBank()->create(['client_id' => $this->client->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);
    $foreign = HourBank::factory()->create();
    $invoice = HoldedInvoice::query()->create(['holded_id' => 'x1', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260001', 'issued_on' => '2026-03-01', 'collection_status' => CollectionStatus::Unpaid]);

    $this->actingAs($this->admin)->post("/facturacion/facturas/{$invoice->id}/enlaces", ['project_id' => $project->id, 'hour_bank_id' => $foreign->id])
        ->assertSessionHasErrors('hour_bank_id');
    $this->actingAs($this->admin)->post("/facturacion/facturas/{$invoice->id}/enlaces", ['project_id' => $project->id, 'hour_bank_id' => $bank->id])->assertSessionHasNoErrors();
    // Repetirlo no duplica.
    $this->actingAs($this->admin)->post("/facturacion/facturas/{$invoice->id}/enlaces", ['project_id' => $project->id, 'hour_bank_id' => $bank->id]);
    expect(HoldedInvoiceLink::query()->count())->toBe(1);

    $automatic = HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $project->id, 'method' => InvoiceLinkMethod::FCode]);
    $this->actingAs($this->admin)->delete("/facturacion/facturas/{$invoice->id}/enlaces/{$automatic->id}")->assertSessionHasErrors('link');
    $manual = HoldedInvoiceLink::query()->where('method', 'manual')->firstOrFail();
    $this->actingAs($this->admin)->delete("/facturacion/facturas/{$invoice->id}/enlaces/{$manual->id}")->assertRedirect();
    expect(HoldedInvoiceLink::query()->pluck('id')->all())->toBe([$automatic->id]);

    // Un enlace de otra factura: 404.
    $otherInvoice = HoldedInvoice::query()->create(['holded_id' => 'x2', 'kind' => HoldedDocumentKind::Invoice, 'issued_on' => '2026-03-01', 'collection_status' => CollectionStatus::Unpaid]);
    $this->actingAs($this->admin)->delete("/facturacion/facturas/{$otherInvoice->id}/enlaces/{$automatic->id}")->assertNotFound();
});

it('el proyecto guarda el tipo «Fee mensual» con sus horas e importe al mes (el importe, solo con view-financials)', function () {
    $project = Project::factory()->create(['client_id' => $this->client->id, 'owner_user_id' => $this->admin->id]);
    $payload = [
        'name' => $project->name, 'client_id' => $this->client->id, 'code' => $project->code, 'color' => $project->color, 'billing_type' => 'monthly_fee',
        'status' => 'active', 'monthly_minutes' => 1200, 'monthly_fee_amount' => '1500.50',
    ];

    $this->actingAs($this->admin)->put("/proyectos/{$project->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();
    $project->refresh();
    expect($project->billing_type)->toBe(BillingType::MonthlyFee)
        ->and($project->monthly_minutes)->toBe(1200)
        ->and((string) $project->monthly_fee_amount)->toBe('1500.50');

    $manager = userWithRole('department_manager');
    $manager->revokePermissionTo(Permission::ViewFinancials->value);
    $project->update(['owner_user_id' => $manager->id]);
    $project->addMember($manager, isManager: true);
    $this->actingAs($manager)->put("/proyectos/{$project->id}", [...$payload, 'monthly_fee_amount' => '1.00', 'monthly_minutes' => 600])->assertSessionHasNoErrors();
    expect((string) $project->fresh()?->monthly_fee_amount)->toBe('1500.50')->and($project->fresh()?->monthly_minutes)->toBe(600);
});

it('app:convert-monthly-fees propone con --dry-run y convierte al confirmar', function () {
    $fee = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ACM-FE1', 'description' => 'Fee mensual de 12 h.']);
    $byCode = Project::factory()->fixedPrice()->create(['client_id' => $this->client->id, 'code' => 'ACM-FE2', 'budget_minutes' => 600]);
    Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ACM-WEB']);
    $invoice = HoldedInvoice::query()->create(['holded_id' => 'f1', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260010', 'issued_on' => '2026-03-01', 'subtotal' => '980.00', 'collection_status' => CollectionStatus::Paid]);
    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $invoice->id, 'project_id' => $fee->id, 'method' => InvoiceLinkMethod::Manual]);

    $this->artisan('app:convert-monthly-fees', ['--dry-run' => true])
        ->expectsOutputToContain('980,00')
        ->expectsOutputToContain('2 proyecto(s) se convertirían')
        ->assertSuccessful();
    expect($fee->fresh()?->billing_type)->toBe(BillingType::TimeAndMaterials);

    $this->artisan('app:convert-monthly-fees')->expectsConfirmation('¿Convertir estos 2 proyecto(s) a «Fee mensual»?', 'no')->assertSuccessful();
    expect($fee->fresh()?->billing_type)->toBe(BillingType::TimeAndMaterials);

    $this->artisan('app:convert-monthly-fees', ['--force' => true, '--codigo' => ['acm-fe1']])->assertSuccessful();
    expect($fee->fresh())->billing_type->toBe(BillingType::MonthlyFee)->monthly_minutes->toBe(720)
        ->and((string) $fee->fresh()?->monthly_fee_amount)->toBe('980.00')
        ->and($byCode->fresh()?->billing_type)->toBe(BillingType::FixedPrice);

    $this->artisan('app:convert-monthly-fees', ['--force' => true])->assertSuccessful();
    expect($byCode->fresh())->billing_type->toBe(BillingType::MonthlyFee)->monthly_minutes->toBe(600);
});

it('la ficha de la bolsa lleva su «Vendido frente a real» diferido, solo con el módulo', function () {
    $project = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'owner_user_id' => $this->admin->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'price_amount' => '1000.00', 'start_date' => '2026-01-01']);

    $this->actingAs($this->admin)->get("/proyectos/{$project->id}/bolsas/{$bank->id}")
        ->assertInertia(fn (Assert $page) => $page->where('billingEnabled', true)->missing('billing')
            ->loadDeferredProps('billing', fn (Assert $reload) => $reload->where('billing.report.units.0.key', 'bank:'.$bank->id)->where('billing.report.units.0.sold_amount', '1000.00')));

    enableBilling(false);
    $this->actingAs($this->admin)->get("/proyectos/{$project->id}/bolsas/{$bank->id}")
        ->assertInertia(fn (Assert $page) => $page->where('billingEnabled', false)->where('billing', null));
});
