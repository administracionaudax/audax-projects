<?php

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Reports\Delivery\ReportAccess;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\Permission;
use App\Jobs\SyncHolded;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Permisos de la Facturación (Fase 12, F1; D-391): matriz por rol de las páginas y de las
| escrituras, el módulo `billing` (apagado, modo de prueba) y lo que ve quien no tiene
| view-financials: «Vendido frente a real» en horas, sin un solo importe.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();

    $this->admin = userWithRole('admin');
    $this->manager = userWithRole('department_manager');
    $this->employee = userWithRole('employee');
    $this->projectManager = userWithRole('employee');
    $this->finance = userWithRole('employee');
    $this->finance->givePermissionTo(Permission::ViewFinancials->value);
    $this->collaborator = User::factory()->collaborator()->create();
    $this->clientCompany = Client::factory()->create();
    $this->portal = User::factory()->portalOf($this->clientCompany)->create();

    $this->project = Project::factory()->hourBank()->create(['client_id' => $this->clientCompany->id, 'owner_user_id' => $this->projectManager->id]);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id, 'price_amount' => '1000.00', 'start_date' => '2026-01-01']);
    $this->project->addMember($this->collaborator);
    $this->invoice = HoldedInvoice::query()->create([
        'holded_id' => 'inv-1', 'kind' => HoldedDocumentKind::Invoice, 'number' => 'F260001', 'number_normalized' => 'F260001', 'issued_on' => '2026-01-10',
        'client_id' => $this->clientCompany->id, 'subtotal' => '1000.00', 'tax_total' => '210.00', 'total' => '1210.00', 'paid_total' => '0.00', 'pending_total' => '1210.00',
        'collection_status' => CollectionStatus::Unpaid,
    ]);

    $this->actors = fn (): array => [
        'admin' => $this->admin,
        'responsable' => $this->manager,
        'empleado' => $this->employee,
        'gestor del proyecto' => $this->projectManager,
        'finanzas (view-financials)' => $this->finance,
        'colaborador' => $this->collaborator,
        'cliente' => $this->portal,
    ];
});

dataset('páginas de facturación', [
    //                                                    admin resp. empl. gestor finan. colab. cliente
    'vendido frente a real' => ['/facturacion/vendido-frente-a-real', [200, 200, 403, 200, 200, 403, 302]],
    'ventas' => ['/facturacion/ventas', [200, 403, 403, 403, 200, 403, 302]],
    'por facturar' => ['/facturacion/por-facturar', [200, 403, 403, 403, 200, 403, 302]],
    'pestaña del proyecto' => ['/proyectos/{project}/facturacion', [200, 200, 403, 200, 200, 403, 302]],
    'facturas' => ['/facturacion/facturas', [200, 403, 403, 403, 200, 403, 302]],
    'ficha de factura' => ['/facturacion/facturas/{invoice}', [200, 403, 403, 403, 200, 403, 302]],
    'por revisar' => ['/facturacion/por-revisar', [200, 403, 403, 403, 200, 403, 302]],
    'ajustes' => ['/facturacion/ajustes', [200, 403, 403, 403, 200, 403, 302]],
    'facturación del cliente' => ['/clientes/{client}/facturacion', [200, 403, 403, 403, 200, 403, 302]],
]);

it('matriz de las páginas por rol', function (string $uri, array $expected) {
    $uri = str_replace(['{project}', '{invoice}', '{client}'], [(string) $this->project->id, (string) $this->invoice->id, (string) $this->clientCompany->id], $uri);

    foreach (array_values(($this->actors)()) as $index => $actor) {
        $status = $this->actingAs($actor)->get($uri)->getStatusCode();

        expect($status)->toBe($expected[$index], "{$uri} como ".array_keys(($this->actors)())[$index]);
    }
})->with('páginas de facturación');

it('matriz de las escrituras por rol', function () {
    Queue::fake();
    $writes = [
        //                                 admin resp. empl. gestor finan. colab. cliente
        'sincronizar' => [fn () => $this->post('/facturacion/sincronizar'), [302, 403, 403, 403, 403, 403, 302]],
        'emisor' => [fn () => $this->put('/facturacion/ajustes', ['country_code' => 'ES', 'legal_name' => 'Audax Studio, S.L.']), [302, 403, 403, 403, 302, 403, 302]],
        'datos fiscales' => [fn () => $this->put("/clientes/{$this->clientCompany->id}/datos-fiscales", ['country_code' => 'ES', 'tax_regime' => 'general', 'language' => 'es']), [302, 403, 403, 403, 302, 403, 302]],
        'enlazar' => [fn () => $this->post("/facturacion/facturas/{$this->invoice->id}/enlaces", ['project_id' => $this->project->id, 'hour_bank_id' => $this->bank->id]), [302, 403, 403, 403, 302, 403, 302]],
    ];

    foreach ($writes as $name => [$request, $expected]) {
        foreach (array_values(($this->actors)()) as $index => $actor) {
            $this->actingAs($actor);
            expect($request()->getStatusCode())->toBe($expected[$index], "{$name} como ".array_keys(($this->actors)())[$index]);
        }
    }

    Queue::assertPushed(SyncHolded::class, 1);
});

it('sin view-financials el informe va en horas, sin importes ni facturas', function () {
    $this->actingAs($this->projectManager)->get('/facturacion/vendido-frente-a-real')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/sold-vs-actual')
            ->where('report.financials', false)
            ->where('filters.can_see_financials', false)
            ->where('scope.own_projects', true)
            ->has('report.units', 1)
            ->missing('report.units.0.invoiced')
            ->missing('report.totals.margin'));

    $this->actingAs($this->projectManager)->get("/proyectos/{$this->project->id}/facturacion")
        ->assertInertia(fn (Assert $page) => $page->where('panel.invoices', null)->missing('panel.report.units.0.sold_amount'));

    $this->actingAs($this->admin)->get('/facturacion/vendido-frente-a-real')
        ->assertInertia(fn (Assert $page) => $page->where('report.financials', true)->where('report.units.0.invoiced', '0.00'));
});

it('un gestor no ve la pestaña de un proyecto que no gestiona ni la de un interno', function () {
    $other = Project::factory()->create();
    $internal = Project::factory()->internal()->create();

    $this->actingAs($this->projectManager)->get("/proyectos/{$other->id}/facturacion")->assertForbidden();
    $this->actingAs($this->admin)->get("/proyectos/{$internal->id}/facturacion")->assertForbidden();
});

it('con el módulo apagado, 404 para todos salvo los admins en modo de prueba', function () {
    enableBilling(false);

    foreach (['/facturacion/vendido-frente-a-real', '/facturacion/facturas', '/facturacion/ajustes', "/proyectos/{$this->project->id}/facturacion"] as $uri) {
        $this->actingAs($this->admin)->get($uri)->assertNotFound();
        $this->actingAs($this->finance)->get($uri)->assertNotFound();
    }

    Setting::set('modules_preview', true);
    $this->actingAs($this->admin)->get('/facturacion/facturas')->assertOk();
    $this->actingAs($this->finance)->get('/facturacion/facturas')->assertNotFound();
    expect(AppModules::enabled(AppModule::Billing))->toBeFalse();
});

it('un admin sin acceso a Facturación no la ve en ningún sitio, ni encendida ni en modo de prueba (D-245)', function () {
    $other = userWithRole('admin');
    $this->actingAs($this->admin)
        ->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$other->id]])
        ->assertRedirect();
    expect(AppModules::excludedIds(AppModule::Billing))->toBe([$other->id]);

    foreach ([true, false] as $enabled) {
        enableBilling($enabled);
        Setting::set('modules_preview', ! $enabled);

        foreach (['/facturacion/vendido-frente-a-real', '/facturacion/facturas', '/facturacion/ajustes', "/proyectos/{$this->project->id}/facturacion", "/clientes/{$this->clientCompany->id}/facturacion"] as $uri) {
            $this->actingAs($other)->get($uri)->assertNotFound();
            $this->actingAs($this->admin)->get($uri)->assertOk();
        }
        $this->actingAs($other)->get('/mis-tareas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('config.modules.billing', false)
            ->where('config.modules_preview', fn ($modules) => ! collect($modules)->contains('billing')));
        expect(Gate::forUser($other)->allows('view-sold-vs-actual'))->toBeFalse();
    }

    // Se le devuelve el acceso.
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => []])->assertRedirect();
    $this->actingAs($other)->get('/facturacion/facturas')->assertOk();
});

it('nadie se quita el acceso a sí mismo y solo un admin cambia quién ve Facturación', function () {
    $this->actingAs($this->admin)
        ->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$this->admin->id]])
        ->assertSessionHasErrors('excluded_user_ids.0');
    $this->actingAs($this->finance)
        ->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$this->admin->id]])
        ->assertForbidden();

    expect(AppModules::excludedIds(AppModule::Billing))->toBe([]);

    $this->actingAs($this->admin)->get('/facturacion/ajustes')->assertInertia(fn (Assert $page) => $page
        ->where('can.access', true)
        ->where('access', fn ($people) => collect($people)->firstWhere('id', $this->admin->id)['self'] === true
            && collect($people)->contains('id', $this->finance->id)
            && ! collect($people)->contains('id', $this->employee->id)
            && ! collect($people)->contains('id', $this->collaborator->id)));
});

it('el módulo viene apagado en una instalación nueva y la migración lo apaga donde ya había módulos', function () {
    Setting::query()->where('key', 'modules')->delete();
    expect(AppModules::enabled(AppModule::Billing))->toBeFalse();

    $migration = require database_path('migrations/2026_10_12_090001_add_billing_off_to_stored_modules.php');
    Setting::set('modules', ['weeklies' => true, 'people' => false]);
    $migration->up();
    expect(Setting::get('modules'))->toBe(['weeklies' => true, 'people' => false, 'billing' => false]);

    Setting::set('modules', ['billing' => true]);
    $migration->up();
    expect(AppModules::enabled(AppModule::Billing))->toBeTrue();
});

it('las habilidades compartidas siguen los permisos', function () {
    $abilities = fn (User $user): array => $this->actingAs($user)->get('/')->inertiaProps('auth.can');

    expect($abilities($this->admin))->toMatchArray(['viewBilling' => true, 'viewSoldVsActual' => true, 'syncHolded' => true])
        ->and($abilities($this->finance))->toMatchArray(['viewBilling' => true, 'viewSoldVsActual' => true, 'syncHolded' => false])
        ->and($abilities($this->projectManager))->toMatchArray(['viewBilling' => false, 'viewSoldVsActual' => true, 'syncHolded' => false])
        ->and($abilities($this->employee))->toMatchArray(['viewBilling' => false, 'viewSoldVsActual' => false, 'exportBillingHours' => false])
        ->and($abilities($this->collaborator))->toMatchArray(['viewBilling' => false, 'viewSoldVsActual' => false]);

    expect($abilities($this->admin)['exportBillingHours'])->toBeTrue()
        ->and($abilities($this->finance)['exportBillingHours'])->toBeTrue()
        ->and($abilities($this->projectManager)['exportBillingHours'])->toBeFalse();

    // Sin el módulo, las horas para facturar siguen con su permiso (D-402); lo demás, no.
    enableBilling(false);
    expect($abilities($this->finance))->toMatchArray(['viewBilling' => false, 'viewSoldVsActual' => false, 'exportBillingHours' => true]);
});

it('el PDF de la factura se pide a Holded la primera vez, se guarda y solo con view-billing', function () {
    Storage::fake('local');
    app()->instance(HoldedApi::class, holdedFake(['invoices' => [holdedInvoice('inv-1', 'F260001', 'c1', '2026-01-10', '1000.00')]]));

    $response = $this->actingAs($this->finance)->get("/facturacion/facturas/{$this->invoice->id}/pdf");
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toBe('inline; filename=F260001.pdf')
        ->and($response->getContent())->toStartWith('%PDF')
        ->and($this->invoice->fresh()?->pdf_path)->toBe('holded/2026/inv-1.pdf');

    $this->actingAs($this->finance)->get("/facturacion/facturas/{$this->invoice->id}/pdf?descargar=1")
        ->assertHeader('Content-Disposition', 'attachment; filename=F260001.pdf');
    $this->actingAs($this->projectManager)->get("/facturacion/facturas/{$this->invoice->id}/pdf")->assertForbidden();
});

it('sin clave de Holded, un PDF que no está guardado da un 503 claro', function () {
    config(['services.holded.driver' => 'holded', 'services.holded.key' => '']);

    $this->actingAs($this->admin)->get("/facturacion/facturas/{$this->invoice->id}/pdf")->assertStatus(503);
});

it('exporta el informe en Excel, CSV, PDF y para imprimir, con los permisos de quien lo pide', function () {
    $url = '/facturacion/vendido-frente-a-real?periodo=anio';

    $this->actingAs($this->admin)->get($url.'&formato=xlsx')->assertOk()->streamedContent();
    $csv = $this->actingAs($this->admin)->get($url.'&formato=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Facturado sin IVA')->toContain('Total');

    $gestor = $this->actingAs($this->projectManager)->get($url.'&formato=csv');
    expect($gestor->streamedContent())->not->toContain('Facturado sin IVA')->toContain('Horas vendidas');

    $this->actingAs($this->admin)->get($url.'&formato=pdf')->assertOk();
    $this->actingAs($this->admin)->get($url.'&formato=imprimir')->assertOk()->assertSee('Vendido frente a real');
    $this->actingAs($this->employee)->get($url.'&formato=csv')->assertForbidden();
});

it('/facturacion lleva a Ventas o, a quien solo ve el vendido frente a real, a ese (D-401 y D-405)', function () {
    $this->actingAs($this->admin)->get('/facturacion')->assertRedirect('/facturacion/ventas');
    $this->actingAs($this->finance)->get('/facturacion')->assertRedirect('/facturacion/ventas');
    $this->actingAs($this->manager)->get('/facturacion')->assertRedirect('/facturacion/vendido-frente-a-real');
    $this->actingAs($this->projectManager)->get('/facturacion')->assertRedirect('/facturacion/vendido-frente-a-real');
    $this->actingAs($this->employee)->get('/facturacion')->assertForbidden();
});

it('las URL antiguas responden con un 301 a las de Facturación, con su query (D-401 y D-405)', function () {
    $moved = [
        '/informes/vendido-frente-a-real' => '/facturacion/vendido-frente-a-real',
        '/informes/facturacion' => '/facturacion/por-facturar',
        '/facturacion/horas-para-facturar' => '/facturacion/por-facturar',
        '/facturacion/informe' => '/facturacion/ventas',
        '/facturacion/contactos' => '/facturacion/por-revisar',
    ];

    foreach ($moved as $old => $new) {
        $this->actingAs($this->finance)->get($old)->assertStatus(301)->assertRedirect($new);
        $location = (string) $this->actingAs($this->finance)->get($old.'?periodo=anio&cliente%5B%5D='.$this->clientCompany->id.'&formato=csv')
            ->assertStatus(301)
            ->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        expect(parse_url($location, PHP_URL_PATH))->toBe($new)
            ->and($query)->toBe(['cliente' => [(string) $this->clientCompany->id], 'formato' => 'csv', 'periodo' => 'anio']);
    }

    // La URL nueva sigue comprobando los permisos y la descarga funciona al seguir la redirección.
    $this->actingAs($this->employee)->followingRedirects()->get('/informes/vendido-frente-a-real')->assertForbidden();
    $csv = $this->actingAs($this->finance)->followingRedirects()->get('/informes/vendido-frente-a-real?periodo=anio&formato=csv');
    $csv->assertOk();
    $this->actingAs($this->finance)->followingRedirects()->get('/facturacion/informe?periodo=anio&formato=csv')->assertOk();
    $this->actingAs($this->employee)->followingRedirects()->get('/facturacion/contactos?vista=todos')->assertForbidden();
    $this->actingAs($this->finance)->followingRedirects()->get('/facturacion/contactos?vista=todos')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/contacts')->where('view', 'todos'));
});

it('con el módulo apagado, las URL antiguas de Facturación no dicen nada (404), salvo la de las horas (D-402 y D-405)', function () {
    enableBilling(false);

    $this->actingAs($this->finance)->get('/facturacion/informe')->assertNotFound();
    $this->actingAs($this->finance)->get('/facturacion/contactos')->assertNotFound();
    $this->actingAs($this->finance)->get('/facturacion/horas-para-facturar')->assertStatus(301)->assertRedirect('/facturacion/por-facturar');
});

it('las horas para facturar no dependen del módulo, pero sí de las exclusiones de Facturación (D-402 y D-247)', function () {
    enableBilling(false);

    $this->actingAs($this->finance)->get('/facturacion/por-facturar')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/hours'));
    $this->actingAs($this->admin)->get('/facturacion/por-facturar?cliente[]='.$this->clientCompany->id.'&formato=csv')->assertOk();
    $this->actingAs($this->employee)->get('/facturacion/por-facturar')->assertForbidden();
    $this->actingAs($this->finance)->get('/facturacion/ventas')->assertNotFound();
    $this->actingAs($this->finance)->get('/facturacion')->assertNotFound();

    enableBilling();
    $excluded = userWithRole('admin');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$excluded->id]])->assertRedirect();
    $this->actingAs($excluded)->get('/facturacion/ventas')->assertNotFound();
    $this->actingAs($excluded)->get('/facturacion/por-revisar')->assertNotFound();
    $this->actingAs($excluded)->get('/facturacion/por-facturar')->assertForbidden();
    $this->actingAs($excluded)->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.can.exportBillingHours', false));
});

it('Informes ya no enseña nada de facturación (D-401)', function () {
    $this->actingAs($this->admin)->get('/informes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reports/index')->missing('billing'));
});

it('los envíos programados de los informes movidos siguen generándose por su tipo (D-401)', function () {
    expect(ReportKind::SoldVsActual->routeName())->toBe('billing.sold-vs-actual')
        ->and(ReportKind::Billing->routeName())->toBe('billing.unbilled')
        ->and(ReportKind::Invoicing->routeName())->toBe('billing.sales');

    foreach (ReportKind::cases() as $kind) {
        expect(Route::has($kind->routeName()))->toBeTrue($kind->value);
    }

    $access = app(ReportAccess::class);
    $request = fn (ReportKind $kind): ReportRequest => new ReportRequest($kind, [], ['periodo' => 'anio']);
    expect($access->allows($request(ReportKind::Invoicing), $this->finance))->toBeTrue()
        ->and($access->allows($request(ReportKind::Invoicing), $this->manager))->toBeFalse()
        ->and($access->allows($request(ReportKind::SoldVsActual), $this->manager))->toBeTrue();

    enableBilling(false);
    expect($access->allows($request(ReportKind::Invoicing), $this->admin))->toBeFalse()
        ->and($access->allows($request(ReportKind::Billing), $this->finance))->toBeTrue();
});
