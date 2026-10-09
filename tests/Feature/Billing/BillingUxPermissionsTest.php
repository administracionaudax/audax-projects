<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Quién abre las pantallas del tramo 2 del rediseño de Facturación (I1, I5 e I10; D-411 a D-415):
| admin, finanzas (view-financials), responsable, empleado, colaborador externo, cliente del portal y
| un admin excluido de Facturación (D-245). Lo que no se puede abrir no dice nada (404 si está
| excluido) y el portal manda a su sitio (302).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'Europe/Madrid'));
    enableBilling();
    $this->admin = userWithRole('admin');
    $this->excluded = userWithRole('admin');
    $this->actingAs($this->admin)->put('/facturacion/ajustes/acceso', ['excluded_user_ids' => [$this->excluded->id]])->assertRedirect();
    $this->actors = billingActors($this->excluded);
});

dataset('pantallas del tramo 2', [
    //                                      admin finan. resp. empl. colab. cliente excluido
    // Quien solo ve «Vendido frente a real» (el responsable) entra allí (D-401).
    'resumen' => ['/facturacion', [200, 200, 302, 403, 403, 302, 404]],
    'por revisar (contactos)' => ['/facturacion/por-revisar?tipo=contactos', [200, 200, 403, 403, 403, 302, 404]],
    'por revisar (facturas)' => ['/facturacion/por-revisar?tipo=facturas', [200, 200, 403, 403, 403, 302, 404]],
    // Por facturar no va detrás del módulo (D-402): al excluido le responde su permiso, con un 403 (D-247).
    'por facturar (lista)' => ['/facturacion/por-facturar', [200, 200, 403, 403, 403, 302, 403]],
    'ajustes (directorio de contactos)' => ['/facturacion/ajustes?contactos=descartados', [200, 200, 403, 403, 403, 302, 404]],
]);

it('matriz de las pantallas por rol', function (string $uri, array $expected) {
    foreach (array_values($this->actors) as $index => $actor) {
        $status = $this->actingAs($actor)->get($uri)->getStatusCode();

        expect($status)->toBe($expected[$index], $uri.' como '.array_keys($this->actors)[$index]);
    }
})->with('pantallas del tramo 2');

it('el responsable entra en «Vendido frente a real» y no ve la entrada del Resumen ni el contador', function () {
    /** @var User $manager */
    $manager = $this->actors['responsable'];

    $this->actingAs($manager)->get('/facturacion')->assertRedirect('/facturacion/vendido-frente-a-real');
    $this->actingAs($manager)->get('/facturacion/vendido-frente-a-real')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billingNav', null)->where('auth.can.viewBilling', false));
});

it('finanzas ve importes en Por facturar y el Resumen; sin el módulo, Por facturar en horas y sin Resumen (D-402)', function () {
    $finance = $this->actors['finanzas'];

    $this->actingAs($finance)->get('/facturacion/por-facturar')->assertInertia(fn (Assert $page) => $page->where('report.financials', true));

    enableBilling(false);
    $this->actingAs($finance)->get('/facturacion')->assertNotFound();
    $this->actingAs($finance)->get('/facturacion/por-revisar')->assertNotFound();
    $this->actingAs($finance)->get('/facturacion/por-facturar')->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.financials', false));
    $this->actingAs($this->excluded)->get('/facturacion/por-facturar')->assertForbidden();
});
