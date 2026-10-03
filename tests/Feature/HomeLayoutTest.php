<?php

use App\Domain\Home\HomeLayout;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Orden de las tarjetas de Inicio (D-138): cada persona guarda el suyo (PUT /inicio/orden) y lo
| restablece (DELETE /inicio/orden). Solo ids de la lista blanca, sin repetir.
*/

beforeEach(function () {
    $this->user = User::factory()->employee()->create();
});

it('la lista blanca es la del contrato compartido con la interfaz', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/home-cards.json')), true);

    expect(HomeLayout::CARDS)->toBe($fixture['cards'])
        ->and(HomeLayout::COLLABORATOR_HIDDEN)->toBe($fixture['collaborator_hidden']);
});

it('guarda el orden y lo restablece', function () {
    $this->actingAs($this->user)
        ->putJson('/inicio/orden', ['cards' => ['mentions', 'timer', 'today-tasks']])
        ->assertNoContent();

    expect($this->user->fresh()->home_layout)->toBe(['mentions', 'timer', 'today-tasks']);

    $this->actingAs($this->user)
        ->deleteJson('/inicio/orden')
        ->assertNoContent();

    expect($this->user->fresh()->home_layout)->toBeNull();
});

it('las rutas tienen sus nombres', function () {
    expect(route('home.layout.update', absolute: false))->toBe('/inicio/orden')
        ->and(route('home.layout.destroy', absolute: false))->toBe('/inicio/orden');
});

it('rechaza ids desconocidos, repetidos, listas vacías o demasiado largas', function (mixed $cards, string $error) {
    $this->user->forceFill(['home_layout' => ['timer']])->save();

    $this->actingAs($this->user)
        ->putJson('/inicio/orden', ['cards' => $cards])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect($this->user->fresh()->home_layout)->toBe(['timer']);
})->with([
    'desconocido' => [['timer', 'pizza'], 'cards.1'],
    'repetido' => [['timer', 'timer'], 'cards.1'],
    'no es texto' => [['timer', 3], 'cards.1'],
    'vacía' => [[], 'cards'],
    'no es una lista' => ['timer', 'cards'],
    'con claves' => [['a' => 'timer'], 'cards'],
    'demasiado larga' => [[...HomeLayout::CARDS, 'timer'], 'cards'],
]);

it('explica en español por qué rechaza un id', function () {
    $this->actingAs($this->user)
        ->putJson('/inicio/orden', ['cards' => ['pizza']])
        ->assertJsonValidationErrors(['cards.0' => __('home.errors.unknown_card')]);

    $this->actingAs($this->user)
        ->putJson('/inicio/orden', ['cards' => ['timer', 'timer']])
        ->assertJsonValidationErrors(['cards.1' => __('home.errors.duplicated_card')]);
});

it('cada persona tiene su propio orden', function () {
    $other = User::factory()->employee()->create();

    $this->actingAs($this->user)->putJson('/inicio/orden', ['cards' => ['timer']])->assertNoContent();
    $this->actingAs($other)->putJson('/inicio/orden', ['cards' => ['mentions']])->assertNoContent();
    $this->actingAs($other)->deleteJson('/inicio/orden')->assertNoContent();

    expect($this->user->fresh()->home_layout)->toBe(['timer'])
        ->and($other->fresh()->home_layout)->toBeNull();
});

it('el orden llega a Inicio en la prop home_layout', function () {
    $this->actingAs($this->user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->component('home', false)->where('home_layout', null));

    $this->user->forceFill(['home_layout' => ['mentions', 'workload', 'timer']])->save();

    $this->actingAs($this->user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('home_layout', ['mentions', 'workload', 'timer']));
});

it('la prop ignora las tarjetas que ya no existen y las repetidas', function () {
    $this->user->forceFill(['home_layout' => ['old-card', 'timer', 'timer', 42, 'mentions']])->save();

    $this->actingAs($this->user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('home_layout', ['timer', 'mentions']));

    $this->user->forceFill(['home_layout' => ['old-card']])->save();

    $this->actingAs($this->user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('home_layout', null));
});

it('el colaborador externo puede guardar y restablecer su orden (D-134)', function () {
    $collaborator = User::factory()->collaborator()->create();

    $this->actingAs($collaborator)
        ->putJson('/inicio/orden', ['cards' => ['mentions', 'timer']])
        ->assertNoContent();

    expect($collaborator->fresh()->home_layout)->toBe(['mentions', 'timer']);

    $this->actingAs($collaborator)->deleteJson('/inicio/orden')->assertNoContent();

    expect($collaborator->fresh()->home_layout)->toBeNull();
});

it('al colaborador no le llegan las tarjetas que no ve aunque las tenga guardadas', function () {
    $collaborator = User::factory()->collaborator()->create(['home_layout' => ['workload', 'mentions', 'indicators', 'absences', 'timer']]);

    $this->actingAs($collaborator)->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('home_layout', ['mentions', 'timer']));
});

it('un cliente del portal no puede usarlo', function () {
    $client = User::factory()->client()->create();

    $this->actingAs($client)->putJson('/inicio/orden', ['cards' => ['timer']])->assertForbidden();

    expect($client->fresh()->home_layout)->toBeNull();
});

it('limita las peticiones', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($this->user)->putJson('/inicio/orden', ['cards' => ['timer']])->assertNoContent();
    }

    $this->actingAs($this->user)->putJson('/inicio/orden', ['cards' => ['timer']])->assertTooManyRequests();
});
