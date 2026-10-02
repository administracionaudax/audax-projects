<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Portal\BanksScenario;

/*
| Aislamiento de las bolsas del portal (P1, SPEC §17: aceptación de la Fase 5). Un cliente con los
| ids de OTRO cliente recibe 404 en el detalle y en el PDF (igual que con un id que no existe: nunca
| un 403 que revele que existe); los internos no entran en el portal (middleware portal); un
| usuario desactivado o de un cliente desactivado, tampoco (D-063).
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);
    $this->paths = fn (int $bankId): array => ["/portal/bolsas/{$bankId}", "/portal/bolsas/{$bankId}/pdf"];
});

it('las bolsas de otro cliente dan 404 en el detalle y en el PDF, como un id que no existe', function () {
    $s = $this->s;

    foreach (($this->paths)($s->foreign->id) as $path) {
        $this->actingAs($s->portal)->get($path)
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
    }

    // Y al revés: el otro cliente no llega a ninguna de las de Bodega Ñandú.
    foreach ([$s->b0, $s->b1, $s->b2, $s->b3] as $bank) {
        foreach (($this->paths)($bank->id) as $path) {
            $this->actingAs($s->otherPortal)->get($path)->assertNotFound();
        }
    }

    foreach (($this->paths)(999999) as $path) {
        $this->actingAs($s->portal)->get($path)->assertNotFound();
    }
});

it('las bolsas propias sí se abren, y una borrada da 404', function () {
    $s = $this->s;

    foreach ([$s->b0, $s->b1, $s->b2, $s->b3] as $bank) {
        foreach (($this->paths)($bank->id) as $path) {
            $this->actingAs($s->portal)->get($path)->assertOk();
        }
    }

    $s->b2->delete();
    foreach (($this->paths)($s->b2->id) as $path) {
        $this->actingAs($s->portal)->get($path)->assertNotFound();
    }
});

it('los usuarios internos no entran en el portal (middleware portal): 403 antes de buscar la bolsa', function () {
    $s = $this->s;
    $paths = ['/portal', ...($this->paths)($s->b1->id), ...($this->paths)(999999)];

    foreach (['admin', 'department_manager', 'employee'] as $role) {
        $internal = userWithRole($role);

        foreach ($paths as $path) {
            $this->actingAs($internal)->get($path)->assertForbidden();
        }
    }
});

it('sin sesión, al login', function () {
    $s = $this->s;

    foreach (['/portal', ...($this->paths)($s->b1->id)] as $path) {
        $this->get($path)->assertRedirect(route('login'));
    }
});

it('un usuario del portal desactivado pierde la sesión y va al login', function () {
    $s = $this->s;
    $s->portal->forceFill(['is_active' => false])->save();

    foreach (['/portal', ...($this->paths)($s->b1->id)] as $path) {
        $this->actingAs($s->portal)->get($path)->assertRedirect(route('login'));
        $this->assertGuest();
    }
});

it('si su cliente se desactiva, sus usuarios pierden el acceso al portal (403)', function () {
    $s = $this->s;
    $s->client->update(['is_active' => false]);

    foreach (['/portal', ...($this->paths)($s->b1->id)] as $path) {
        $this->actingAs($s->portal)->get($path)->assertForbidden();
    }

    // El otro cliente sigue con su acceso.
    $this->actingAs($s->otherPortal)->get('/portal')->assertOk();
});

it('un usuario cliente sin cliente asignado no tiene portal (403)', function () {
    $s = $this->s;
    $orphan = User::factory()->client()->create();

    foreach (['/portal', ...($this->paths)($s->b1->id)] as $path) {
        $this->actingAs($orphan)->get($path)->assertForbidden();
    }
});

it('el cliente no puede abrir la app interna ni la bolsa interna: vuelve a su portal', function () {
    $s = $this->s;

    foreach (['/proyectos', "/proyectos/{$s->web->id}/bolsas/{$s->b1->id}", "/proyectos/{$s->web->id}/bolsas/{$s->b1->id}/pdf", '/bolsas'] as $path) {
        $this->actingAs($s->portal)->get($path)->assertRedirect(route('portal.home'));
    }
});
