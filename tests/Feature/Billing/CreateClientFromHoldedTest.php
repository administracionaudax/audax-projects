<?php

use App\Domain\Billing\HoldedClientCreator;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| «Crear cliente» desde un contacto de Holded sin cliente (D-430, cambia D-387): el diálogo con los
| datos ya rellenos, el aviso de un cliente con el mismo NIF o un nombre muy parecido (con casar en
| vez de crear), el cliente activo con su ficha fiscal, el contacto casado a mano con sus facturas,
| la auditoría con el origen, quién puede y que la sincronización no lo deshace.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');

    $this->monto = Client::factory()->create(['name' => 'Montó']);
    $this->contact = HoldedContact::query()->create([
        'holded_id' => 'c-new', 'name' => 'NARANJAS DEL TURIA, S.L.', 'tax_id' => 'ES B-46111222', 'tax_id_normalized' => 'B46111222',
        'email' => 'Admin@NaranjasTuria.es', 'country_code' => 'ES',
        'address' => ['address' => 'Camí Real, 12', 'postal_code' => '46100', 'city' => 'Burjassot', 'province' => 'Valencia'],
    ]);
    $this->invoice = HoldedInvoice::query()->create([
        'holded_id' => 'h-1', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260100', 'holded_contact_id' => 'c-new', 'issued_on' => '2026-03-01',
        'subtotal' => '1000.00', 'tax_total' => '210.00', 'total' => '1210.00', 'paid_total' => '0.00', 'pending_total' => '1210.00',
        'collection_status' => CollectionStatus::Unpaid,
    ]);
    $this->url = "/facturacion/contactos/{$this->contact->id}/cliente";
    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Naranjas del Turia', 'tax_id' => 'B46111222', 'email' => 'admin@naranjasturia.es', 'legal_name' => 'NARANJAS DEL TURIA, S.L.',
        'address' => 'Camí Real, 12', 'postal_code' => '46100', 'city' => 'Burjassot', 'province' => 'Valencia', 'country_code' => 'ES', ...$overrides,
    ];
});

it('el nombre es el comercial o la razón social sin la forma jurídica', function (string $name, ?string $trade, string $expected) {
    $contact = new HoldedContact(['name' => $name, 'trade_name' => $trade]);

    expect(HoldedClientCreator::suggestedName($contact))->toBe($expected);
})->with([
    ['PINTURAS MONTÓ, S.A.U', null, 'PINTURAS MONTÓ'],
    ['Hoteles Mirador, S.L.', null, 'Hoteles Mirador'],
    ['Estudio Nébula SL', null, 'Estudio Nébula'],
    ['Cooperativa del Camp S. Coop. V.', null, 'Cooperativa del Camp'],
    ['Mesa y Mantel', null, 'Mesa y Mantel'],
    ['CONSTRUCCIONES LAMAS SA', 'Lamas', 'Lamas'],
]);

it('el diálogo llega relleno y sin avisos si nadie se le parece', function () {
    $this->actingAs($this->admin)->getJson($this->url)
        ->assertOk()
        ->assertJson([
            'draft' => [
                'name' => 'NARANJAS DEL TURIA', 'tax_id' => 'B46111222', 'email' => 'Admin@NaranjasTuria.es', 'legal_name' => 'NARANJAS DEL TURIA, S.L.',
                'address' => 'Camí Real, 12', 'postal_code' => '46100', 'city' => 'Burjassot', 'province' => 'Valencia', 'country_code' => 'ES',
            ],
            'candidates' => [],
        ]);
});

it('avisa de un cliente con el mismo NIF o un nombre muy parecido y deja casar con él en vez de crear', function () {
    $sameTaxId = Client::factory()->create(['name' => 'Turia Cítricos', 'tax_id' => 'B-46111222']);
    $similar = Client::factory()->create(['name' => 'Naranjas Turia']);

    $this->actingAs($this->admin)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('candidates.0.id', $sameTaxId->id)
        ->assertJsonPath('candidates.0.reason', 'nif')
        ->assertJsonPath('candidates.1.id', $similar->id)
        ->assertJsonPath('candidates.1.reason', 'parecido')
        ->assertJsonCount(2, 'candidates');

    // Crear sin confirmar no deja: avisa con los nombres.
    $this->actingAs($this->admin)->from('/facturacion/por-revisar')->post($this->url, ($this->payload)())
        ->assertSessionHasErrors(['candidates' => __('billing_rules.create_client.duplicates', ['clients' => 'Turia Cítricos, Naranjas Turia'])]);
    expect(Client::query()->count())->toBe(3);

    // Casar con el del NIF, por la acción de siempre.
    $this->actingAs($this->admin)->put("/facturacion/contactos/{$this->contact->id}", ['action' => 'assign', 'client_id' => $sameTaxId->id])->assertRedirect();
    expect($this->contact->fresh()->client_id)->toBe($sameTaxId->id)
        ->and($this->invoice->fresh()->client_id)->toBe($sameTaxId->id);
});

it('crea el cliente activo con su ficha fiscal, casa el contacto a mano, le pasa sus facturas y lo anota en la auditoría', function () {
    $this->actingAs($this->admin)->from('/facturacion/por-revisar?tipo=contactos')->post($this->url, ($this->payload)(['province' => '']))
        ->assertRedirect('/facturacion/por-revisar?tipo=contactos')
        ->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Naranjas del Turia')->sole();
    $contact = $this->contact->fresh();
    $profile = ClientBillingProfile::query()->where('client_id', $client->id)->sole();

    expect($client->is_active)->toBeTrue()
        ->and($client->tax_id)->toBe('B46111222')
        ->and($client->contact_email)->toBe('admin@naranjasturia.es')
        ->and($contact->client_id)->toBe($client->id)
        ->and($contact->match_method)->toBe(HoldedContact::MATCH_MANUAL)
        ->and($contact->resolved_by)->toBe($this->admin->id)
        ->and($this->invoice->fresh()->client_id)->toBe($client->id)
        ->and($profile->only(['legal_name', 'address', 'postal_code', 'city', 'province', 'country_code']))->toBe([
            'legal_name' => 'NARANJAS DEL TURIA, S.L.', 'address' => 'Camí Real, 12', 'postal_code' => '46100', 'city' => 'Burjassot',
            // Lo vaciado en el diálogo no lo vuelve a rellenar Holded.
            'province' => null, 'country_code' => 'ES',
        ]);

    $created = Activity::query()->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)->where('event', 'created')->sole();
    $origin = Activity::query()->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)->where('event', HoldedClientCreator::AUDIT_EVENT)->sole();
    expect($created->causer_id)->toBe($this->admin->id)
        ->and($origin->causer_id)->toBe($this->admin->id)
        ->and($origin->getProperty('origin'))->toBe('holded')
        ->and($origin->getProperty('holded_id'))->toBe('c-new')
        ->and($origin->getProperty('contact_name'))->toBe('NARANJAS DEL TURIA, S.L.');

    // Ya tiene cliente: ni el diálogo ni crear otro.
    $this->actingAs($this->admin)->getJson($this->url)->assertStatus(409);
    $this->actingAs($this->admin)->post($this->url, ($this->payload)(['name' => 'Otro']))->assertStatus(409);
});

it('confirmando que es otro, crea aunque haya un parecido; un nombre que ya existe, nunca', function () {
    Client::factory()->create(['name' => 'Naranjas Turia']);

    $this->actingAs($this->admin)->post($this->url, ($this->payload)(['name' => 'naranjas turia', 'confirmed' => true]))
        ->assertSessionHasErrors(['name' => __('clients.errors.name_taken')]);

    $this->actingAs($this->admin)->post($this->url, ($this->payload)(['confirmed' => true]))->assertSessionHasNoErrors();
    expect(Client::query()->where('name', 'Naranjas del Turia')->exists())->toBeTrue();
});

it('la sincronización con Holded respeta el cliente creado y el casado a mano', function () {
    $this->actingAs($this->admin)->post($this->url, ($this->payload)())->assertSessionHasNoErrors();
    $client = Client::query()->where('name', 'Naranjas del Turia')->sole();

    syncHolded(holdedFake([
        'contacts' => [holdedContact('c-new', 'NARANJAS DEL TURIA, S.L.', 'B46111222', ['billing_address' => ['address' => 'Otra calle, 1', 'city' => 'Valencia', 'postal_code' => '46001', 'province' => 'Valencia', 'country_code' => 'ES']])],
        'invoices' => [holdedInvoice('h-1', 'F260100', 'c-new', '2026-03-01', '1000.00')],
    ]));

    expect($this->contact->fresh()->client_id)->toBe($client->id)
        ->and($this->contact->fresh()->match_method)->toBe(HoldedContact::MATCH_MANUAL)
        ->and($this->invoice->fresh()->client_id)->toBe($client->id)
        ->and($client->fresh()->is_active)->toBeTrue()
        // Lo escrito en Audax manda (D-381): la sincronización no cambia la dirección.
        ->and(ClientBillingProfile::query()->where('client_id', $client->id)->value('address'))->toBe('Camí Real, 12');
});

it('quién puede: view-billing y, además, crear clientes (admins y responsables)', function () {
    $this->excluded = userWithRole('admin');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$this->excluded->id]])->assertRedirect();
    $actors = billingActors($this->excluded);
    // Un responsable con view-financials (ve Facturación y puede crear clientes).
    $managerWithFinancials = userWithRole('department_manager');
    $managerWithFinancials->givePermissionTo(Permission::ViewFinancials->value);

    //            admin finan. resp. empl. colab. cliente excluido (el portal responde 403 a una petición JSON)
    $json = [200, 403, 403, 403, 403, 403, 404];
    $expected = [200, 403, 403, 403, 403, 302, 404];
    foreach (array_values($actors) as $index => $actor) {
        expect($this->actingAs($actor)->get($this->url, ['Accept' => 'application/json'])->getStatusCode())
            ->toBe($json[$index], 'GET como '.array_keys($actors)[$index]);
    }
    foreach (array_values($actors) as $index => $actor) {
        if ($index === 0) {
            continue;
        }
        expect($this->actingAs($actor)->post($this->url, ($this->payload)())->getStatusCode())
            ->toBe($expected[$index], 'POST como '.array_keys($actors)[$index]);
    }
    expect(Client::query()->where('name', 'Naranjas del Turia')->exists())->toBeFalse();

    $this->actingAs($managerWithFinancials)->post($this->url, ($this->payload)())->assertSessionHasNoErrors()->assertRedirect();
    expect(Client::query()->where('name', 'Naranjas del Turia')->exists())->toBeTrue();
});

it('la bandeja y el directorio dicen si se puede crear el cliente', function () {
    $finance = userWithRole('employee');
    $finance->givePermissionTo(Permission::ViewFinancials->value);

    $this->actingAs($this->admin)->get('/facturacion/por-revisar?tipo=contactos')
        ->assertInertia(fn (Assert $page) => $page->where('can.create_client', true));
    $this->actingAs($finance)->get('/facturacion/por-revisar?tipo=contactos')
        ->assertInertia(fn (Assert $page) => $page->where('can.create_client', false));
    $this->actingAs($this->admin)->get('/facturacion/ajustes')
        ->assertInertia(fn (Assert $page) => $page->where('can.create_client', true));
    $this->actingAs($finance)->get('/facturacion/ajustes')
        ->assertInertia(fn (Assert $page) => $page->where('can.create_client', false));
});
