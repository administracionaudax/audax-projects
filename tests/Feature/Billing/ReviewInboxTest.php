<?php

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Domain\Billing\ReviewUndo;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLinkMethod;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| La bandeja «Por revisar» (I5, D-413 y D-414): contactos sin casar o por confirmar y facturas sin
| proyecto, cada uno con su propuesta (motivo y confianza), «Aceptar las de confianza alta»
| recalculado en el servidor, «Deshacer» la última acción y el directorio de contactos en Ajustes.
| Nunca se crea un cliente desde Holded (D-387).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');

    $this->lamas = Client::factory()->create(['name' => 'Construcciones Lamas', 'tax_id' => 'B12345678']);
    $this->monto = Client::factory()->create(['name' => 'Montó']);
    $this->mirador = Client::factory()->create(['name' => 'Hoteles Mirador']);
    $this->twinA = Client::factory()->create(['name' => 'Gemelos Norte', 'tax_id' => 'B99999999']);
    $this->twinB = Client::factory()->create(['name' => 'Gemelos Sur', 'tax_id' => 'B99999999']);

    $this->invoice = function (string $number, ?Client $client, string $subtotal, array $lines = [['Desarrollo', '10', '600.00']], ?string $contact = null, string $date = '2026-03-02'): HoldedInvoice {
        $invoice = HoldedInvoice::query()->create([
            'holded_id' => 'h-'.$number, 'kind' => HoldedDocumentKind::Invoice, 'number' => $number, 'number_normalized' => $number,
            'holded_contact_id' => $contact, 'client_id' => $client?->id, 'issued_on' => $date,
            'subtotal' => $subtotal, 'tax_total' => '0.00', 'total' => $subtotal, 'paid_total' => '0.00', 'pending_total' => $subtotal,
            'collection_status' => CollectionStatus::Unpaid,
        ]);
        foreach ($lines as $n => [$name, $units, $amount]) {
            $invoice->lines()->create(['position' => $n + 1, 'name' => $name, 'units' => $units, 'unit_price' => '60', 'discount_pct' => '0', 'subtotal' => $amount, 'tax_rate' => '0']);
        }

        return $invoice;
    };
    $this->contact = fn (string $id, string $name, array $extra = []): HoldedContact => HoldedContact::query()->create(['holded_id' => $id, 'name' => $name, ...$extra]);
    $this->page = fn (string $tab) => $this->actingAs($this->admin)->get('/facturacion/por-revisar?tipo='.$tab)->assertOk();
});

it('propone el mejor cliente de cada contacto con su motivo y su confianza (amplía D-248)', function () {
    // Sus facturas ya van a un proyecto de Lamas por el código F: alta.
    $byCode = ($this->contact)('c-code', 'LAMAS CONSTRUCCIONES Y REFORMAS');
    $project = Project::factory()->create(['client_id' => $this->lamas->id, 'code' => 'LAM-INT']);
    $linked = ($this->invoice)('F260001', null, '900.00', contact: 'c-code');
    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $linked->id, 'project_id' => $project->id, 'method' => InvoiceLinkMethod::FCode]);
    // El NIF de un único cliente (casaría en la próxima lectura): alta.
    ($this->contact)('c-nif', 'Otro nombre, S.L.', ['tax_id' => 'B12345678', 'tax_id_normalized' => 'B12345678']);
    // El NIF de dos clientes: media, el que más palabras comparte.
    ($this->contact)('c-twin', 'GEMELOS SUR, S.L.', ['tax_id' => 'B99999999', 'tax_id_normalized' => 'B99999999']);
    // Casado por un nombre parecido, por confirmar: media.
    $approx = ($this->contact)('c-approx', 'PINTURAS MONTÓ, S.A.U');
    app(HoldedContactMatcher::class)->match($approx);
    $approx->save();
    // Solo una palabra en común: baja.
    ($this->contact)('c-word', 'Mirador del Puerto Eventos');
    // Nada en común: sin propuesta.
    ($this->contact)('c-none', 'Zapatería Quintana');
    // Ya resueltos: no salen.
    ($this->contact)('c-done', 'Hoteles Mirador', ['client_id' => $this->mirador->id, 'match_method' => HoldedContact::MATCH_NAME]);
    ($this->contact)('c-ignored', 'Proveedor', ['ignored_at' => now()]);

    ($this->page)('contactos')->assertInertia(function (Assert $page) use ($approx) {
        $page->component('billing/review')->where('tab', 'contactos')->where('counts.contactos', 6);
        $rows = collect($page->toArray()['props']['contacts'])->keyBy('name');

        expect($rows->keys()->first())->toBe('LAMAS CONSTRUCCIONES Y REFORMAS') // primero lo que más ha facturado
            ->and($rows['LAMAS CONSTRUCCIONES Y REFORMAS']['proposal'])->toBe(['client' => ['id' => $this->lamas->id, 'name' => 'Construcciones Lamas'], 'reason' => 'codigo_f', 'confidence' => 'alta'])
            ->and($rows['Otro nombre, S.L.']['proposal'])->toMatchArray(['reason' => 'nif', 'confidence' => 'alta'])
            ->and($rows['GEMELOS SUR, S.L.']['proposal'])->toBe(['client' => ['id' => $this->twinB->id, 'name' => 'Gemelos Sur'], 'reason' => 'nif', 'confidence' => 'media'])
            ->and($rows['PINTURAS MONTÓ, S.A.U']['proposal'])->toMatchArray(['client' => ['id' => $this->monto->id, 'name' => 'Montó'], 'reason' => 'parecido', 'confidence' => 'media'])
            ->and($rows['Mirador del Puerto Eventos']['proposal'])->toMatchArray(['client' => ['id' => $this->mirador->id, 'name' => 'Hoteles Mirador'], 'reason' => 'palabras', 'confidence' => 'baja'])
            ->and($rows['Zapatería Quintana']['proposal'])->toBeNull()
            ->and($rows->has('Proveedor'))->toBeFalse();
        expect($approx->refresh()->match_method)->toBe(HoldedContact::MATCH_APPROX);
    });
});

it('propone el proyecto de cada factura sin proyecto con su confianza; sin cliente, ninguno', function () {
    $banks = Project::factory()->hourBank()->create(['client_id' => $this->lamas->id, 'code' => 'LAM-BH', 'start_date' => '2026-01-01']);
    $bank = HourBank::factory()->create(['project_id' => $banks->id, 'name' => 'Bolsa marzo', 'start_date' => '2026-03-01']);
    Project::factory()->create(['client_id' => $this->mirador->id, 'code' => 'MIR-A', 'billing_type' => BillingType::TimeAndMaterials, 'start_date' => '2026-01-01']);
    Project::factory()->create(['client_id' => $this->mirador->id, 'code' => 'MIR-B', 'billing_type' => BillingType::TimeAndMaterials, 'start_date' => '2026-01-01']);
    Project::factory()->create(['client_id' => $this->monto->id, 'code' => 'MON-OLD', 'billing_type' => BillingType::TimeAndMaterials, 'start_date' => '2024-01-01', 'due_date' => '2024-06-30', 'status' => ProjectStatus::Completed]);

    ($this->invoice)('F260010', $this->lamas, '3000.00', [['bolsadehoras', '50', '3000.00']]);
    ($this->invoice)('F260011', $this->mirador, '600.00');
    ($this->invoice)('F260012', $this->monto, '1200.00');
    ($this->invoice)('F260013', null, '100.00', contact: 'c-x');

    ($this->page)('facturas')->assertInertia(function (Assert $page) use ($banks, $bank) {
        $page->where('tab', 'facturas')->where('counts.facturas', 4)->has('targets');
        $rows = collect($page->toArray()['props']['invoices'])->keyBy('number');

        expect($rows->keys()->all())->toBe(['F260010', 'F260012', 'F260011', 'F260013']) // de más a menos importe
            ->and($rows['F260010']['proposal'])->toMatchArray(['project' => ['id' => $banks->id, 'code' => 'LAM-BH', 'name' => $banks->name], 'bank' => ['id' => $bank->id, 'name' => 'Bolsa marzo'], 'reason' => 'bank', 'confidence' => 'alta', 'dated' => true])
            ->and($rows['F260011']['proposal'])->toMatchArray(['confidence' => 'media'])
            ->and($rows['F260011']['alternatives'])->toHaveCount(1)
            ->and($rows['F260012']['proposal'])->toMatchArray(['confidence' => 'baja', 'dated' => false])
            ->and($rows['F260013']['proposal'])->toBeNull();
    });
});

it('«Aceptar las de confianza alta» aplica solo las altas y se deshace de una vez (D-414)', function () {
    $code = ($this->contact)('c-code', 'LAMAS CONSTRUCCIONES Y REFORMAS');
    $project = Project::factory()->create(['client_id' => $this->lamas->id, 'code' => 'LAM-INT', 'start_date' => '2026-01-01']);
    $linked = ($this->invoice)('F260001', null, '900.00', contact: 'c-code');
    HoldedInvoiceLink::query()->create(['holded_invoice_id' => $linked->id, 'project_id' => $project->id, 'method' => InvoiceLinkMethod::FCode]);
    $twin = ($this->contact)('c-twin', 'GEMELOS SUR, S.L.', ['tax_id' => 'B99999999', 'tax_id_normalized' => 'B99999999']);

    $this->actingAs($this->admin)->from('/facturacion/por-revisar?tipo=contactos')
        ->post('/facturacion/por-revisar/aceptar', ['tipo' => 'contactos'])
        ->assertRedirect('/facturacion/por-revisar?tipo=contactos');

    expect($code->refresh()->client_id)->toBe($this->lamas->id)
        ->and($code->match_method)->toBe(HoldedContact::MATCH_MANUAL)
        ->and($linked->refresh()->client_id)->toBe($this->lamas->id)
        ->and($twin->refresh()->client_id)->toBeNull();

    // Las facturas: la de Lamas (ya con cliente) se enlaza sola con su único proyecto vivo.
    $unlinked = ($this->invoice)('F260002', $this->lamas, '600.00');
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/aceptar', ['tipo' => 'facturas'])->assertRedirect();
    expect($unlinked->links()->pluck('project_id')->all())->toBe([$project->id])
        ->and($unlinked->links()->value('method'))->toBe(InvoiceLinkMethod::Manual);

    ($this->page)('facturas')->assertInertia(fn (Assert $page) => $page->where('undo.count', 1)->where('counts.facturas', 0));

    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect();
    expect($unlinked->links()->count())->toBe(0);
    ($this->page)('facturas')->assertInertia(fn (Assert $page) => $page->where('undo', null)->where('counts.facturas', 1));
});

it('casar, descartar o enlazar una a una también se deshace, y el contacto vuelve como estaba', function () {
    $contact = ($this->contact)('c-1', 'Estudio Nébula, S.L.');
    $invoice = ($this->invoice)('F260003', null, '2400.00', contact: 'c-1');

    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'assign', 'client_id' => $this->mirador->id])->assertRedirect();
    expect($invoice->refresh()->client_id)->toBe($this->mirador->id);
    ($this->page)('contactos')->assertInertia(fn (Assert $page) => $page->where('undo.count', 1)->where('undo.message', fn (string $message) => str_contains($message, 'Hoteles Mirador')));

    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect();
    expect($contact->refresh()->client_id)->toBeNull()
        ->and($contact->match_method)->toBeNull()
        ->and($contact->resolved_by)->toBeNull()
        ->and($invoice->refresh()->client_id)->toBeNull();

    // Descartar y deshacer.
    $this->actingAs($this->admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'ignore'])->assertRedirect();
    expect($contact->refresh()->ignored_at)->not->toBeNull();
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect();
    expect($contact->refresh()->ignored_at)->toBeNull();

    // Enlazar a mano y deshacer: se quita el enlace creado.
    $project = Project::factory()->create(['client_id' => $this->mirador->id]);
    $this->actingAs($this->admin)->post("/facturacion/facturas/{$invoice->id}/enlaces", ['project_id' => $project->id])->assertRedirect();
    expect($invoice->links()->count())->toBe(1);
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect();
    expect($invoice->links()->count())->toBe(0);

    // Sin nada que deshacer, no pasa nada.
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/deshacer')->assertRedirect()->assertSessionMissing(ReviewUndo::KEY);
});

it('nunca crea un cliente desde Holded (D-387)', function () {
    ($this->contact)('c-1', 'Cliente Nuevo Que No Existe');
    $before = Client::query()->count();

    $this->actingAs($this->admin)->post('/facturacion/por-revisar/aceptar', ['tipo' => 'contactos'])->assertRedirect();
    $this->actingAs($this->admin)->post('/facturacion/por-revisar/aceptar', ['tipo' => 'facturas'])->assertRedirect();

    expect(Client::query()->count())->toBe($before);
});

it('sin tipo abre la pestaña que tiene algo, y Ajustes lleva el directorio de contactos', function () {
    ($this->invoice)('F260004', $this->lamas, '100.00');

    $this->actingAs($this->admin)->get('/facturacion/por-revisar')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'facturas')->where('counts', ['contactos' => 0, 'facturas' => 1])
            ->where('coverage.invoices_total', 1)->where('coverage.invoices_linked', 0));

    ($this->contact)('c-1', 'Hoteles Mirador', ['client_id' => $this->mirador->id, 'match_method' => HoldedContact::MATCH_NAME]);
    ($this->contact)('c-2', 'Proveedor', ['ignored_at' => now()]);
    $this->actingAs($this->admin)->get('/facturacion/ajustes?contactos=descartados')
        ->assertInertia(fn (Assert $page) => $page->component('billing/settings')
            ->where('contacts.view', 'descartados')
            ->has('contacts.rows', 2)
            ->where('contacts.rows', fn ($rows) => collect($rows)->firstWhere('name', 'Proveedor')['ignored'] === true)
            ->has('contacts.clients', 5));
});

dataset('acciones de la bandeja', [
    //                                                     admin finan. resp. empl. colab. cliente excluido
    'aceptar en bloque' => ['/facturacion/por-revisar/aceptar', [302, 302, 403, 403, 403, 302, 404]],
    'deshacer' => ['/facturacion/por-revisar/deshacer', [302, 302, 403, 403, 403, 302, 404]],
]);

it('las acciones de la bandeja exigen view-billing (D-245)', function (string $uri, array $expected) {
    $excluded = userWithRole('admin');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$excluded->id]])->assertRedirect();
    $actors = billingActors($excluded);

    foreach (array_values($actors) as $index => $actor) {
        $status = $this->actingAs($actor)->post($uri, ['tipo' => 'contactos'])->getStatusCode();
        expect($status)->toBe($expected[$index], $uri.' como '.array_keys($actors)[$index]);
    }
})->with('acciones de la bandeja');
