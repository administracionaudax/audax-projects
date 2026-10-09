<?php

use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Models\SalesDocument;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Permisos y módulo (PLAN-EMISION §3.2 y §9; P-5, D-417, D-418 y D-428): la matriz por papel, el
| módulo apagado (404), el modo de prueba (solo admins) y las exclusiones de Facturación (D-245).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient();
    $this->draft = invoicingDraft($this->admin, $this->client);
    $this->issued = invoicingIssue($this->admin, $this->client);

    $this->finance = userWithRole('employee');
    $this->finance->givePermissionTo(Permission::ViewFinancials->value);
    $this->issuerUser = userWithRole('employee');
    $this->issuerUser->givePermissionTo([Permission::ViewFinancials->value, Permission::ManageBilling->value]);
});

$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/billing/document-actions.json'), true);

it('la matriz estado → acciones es la del caso compartido', function (array $case) {
    expect(DocumentActions::for($case['state'], $case['role']))->toBe($case['actions']);
})->with(array_map(fn (array $case): array => [$case], $fixture['cases']));

it('cada papel ve y hace lo suyo', function () {
    $roles = fn (User $user): ?string => DocumentActions::role($user);
    $manager = userWithRole('department_manager');

    expect($roles($this->admin))->toBe('admin')
        ->and($roles($this->issuerUser))->toBe('manage')
        ->and($roles($this->finance))->toBe('view')
        ->and($roles($manager))->toBeNull()
        ->and($roles(userWithRole('employee')))->toBeNull()
        ->and($roles(User::factory()->collaborator()->create()))->toBeNull();

    // Quien prepara: crea y edita borradores, no emite.
    $this->actingAs($this->finance)->get('/facturacion/facturas/nueva')->assertOk();
    $this->actingAs($this->finance)->get("/facturacion/documentos/{$this->draft->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/documents/show')->where('actions', ['view', 'edit', 'delete', 'duplicate', 'download_pdf', 'edit_non_fiscal']));
    $this->actingAs($this->finance)->post("/facturacion/documentos/{$this->draft->id}/emitir")->assertForbidden();
    $this->actingAs($this->finance)->post("/facturacion/documentos/{$this->issued->id}/anular", ['reason' => 'No tiene permiso'])->assertForbidden();

    // Quien emite: emite, anula y rectifica, pero no anula el registro.
    $this->actingAs($this->issuerUser)->post("/facturacion/documentos/{$this->draft->id}/emitir")->assertRedirect();
    expect(SalesDocument::query()->find($this->draft->id)->full_number)->toBe('F270002');
    $this->actingAs($this->issuerUser)->post("/facturacion/documentos/{$this->issued->id}/anular-registro", ['reason' => 'No es admin'])->assertForbidden();

    // Los gestores de proyecto, la plantilla, los colaboradores y los clientes no la ven.
    foreach ([$manager, userWithRole('employee'), User::factory()->collaborator()->create(), User::factory()->portalOf($this->client)->create()] as $user) {
        $status = $this->actingAs($user)->get("/facturacion/documentos/{$this->issued->id}")->status();
        expect($status)->toBeIn([302, 403, 404]);
    }
});

it('con el módulo apagado, nada de emitir existe (404), y la navegación no lo ofrece', function () {
    enableInvoicing(false);

    foreach (['/facturacion/facturas/nueva', "/facturacion/documentos/{$this->issued->id}", "/facturacion/documentos/{$this->issued->id}/pdf"] as $url) {
        $this->actingAs($this->admin)->get($url)->assertNotFound();
    }
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->draft->id}/emitir")->assertNotFound();

    expect($this->actingAs($this->admin)->get('/facturacion/facturas')->inertiaProps('auth.can'))->toMatchArray(['useInvoicing' => false, 'manageBilling' => false]);
});

it('en modo de prueba la ven solo los admins con acceso a Facturación', function () {
    Setting::set('modules', [...(array) Setting::get('modules'), 'billing' => false, 'invoicing' => false]);
    Setting::set('modules_preview', true);

    $this->actingAs($this->admin)->get('/facturacion/facturas/nueva')->assertOk();
    $this->actingAs($this->finance)->get('/facturacion/facturas/nueva')->assertNotFound();

    // Un admin excluido de Facturación (D-245) tampoco ve la emisión.
    $excluded = userWithRole('admin');
    AppModules::setExcluded(AppModule::Billing, [$excluded->id]);
    $this->actingAs($excluded)->get('/facturacion/facturas/nueva')->assertNotFound();
    expect($this->actingAs($excluded)->get('/')->inertiaProps('auth.can'))->toMatchArray(['useInvoicing' => false, 'manageBilling' => false, 'voidInvoices' => false]);
});

it('la migración apaga el módulo donde ya había módulos guardados', function () {
    Setting::query()->where('key', 'modules')->delete();
    Setting::flushCache();
    expect(AppModules::enabled(AppModule::Invoicing))->toBeFalse();

    $migration = require database_path('migrations/2026_10_14_090002_add_invoicing_off_to_stored_modules.php');
    Setting::set('modules', ['weeklies' => true, 'billing' => true]);
    $migration->up();

    expect(Setting::get('modules'))->toBe(['weeklies' => true, 'billing' => true, 'invoicing' => false]);
});
