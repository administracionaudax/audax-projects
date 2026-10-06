<?php

use App\Domain\Navigation\NavSections;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Secciones plegables de la barra lateral (D-260): cada persona guarda las que tiene plegadas
| (PUT /menu/secciones) y le llegan en la prop compartida `navCollapsed`. Solo ids de la lista
| blanca, sin repetir; una lista vacía vuelve al valor por defecto (todas desplegadas).
*/

beforeEach(function () {
    $this->user = User::factory()->employee()->create();
});

it('la lista blanca es la del contrato compartido con la interfaz', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/nav-sections.json')), true);

    expect(NavSections::SECTIONS)->toBe($fixture['sections']);
});

it('la ruta tiene su nombre', function () {
    expect(route('nav.sections.update', absolute: false))->toBe('/menu/secciones');
});

it('por defecto no hay ninguna sección plegada', function () {
    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('navCollapsed', []));
});

it('guarda las secciones plegadas en el orden de la barra y le llegan en las props', function () {
    $this->actingAs($this->user)
        ->putJson('/menu/secciones', ['collapsed' => ['admin', 'projects']])
        ->assertNoContent();

    expect($this->user->fresh()->nav_collapsed)->toBe(['projects', 'admin']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('navCollapsed', ['projects', 'admin']));
});

it('una lista vacía las despliega todas (vuelve a null)', function () {
    $this->user->forceFill(['nav_collapsed' => ['weekly']])->save();

    $this->actingAs($this->user)
        ->putJson('/menu/secciones', ['collapsed' => []])
        ->assertNoContent();

    expect($this->user->fresh()->nav_collapsed)->toBeNull();
});

it('ignora en las props una sección guardada que ya no existe', function () {
    $this->user->forceFill(['nav_collapsed' => ['weekly', 'old-section']])->save();

    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('navCollapsed', ['weekly']));
});

it('rechaza ids desconocidos, repetidos o que no son una lista', function (mixed $collapsed, string $error) {
    $this->user->forceFill(['nav_collapsed' => ['weekly']])->save();

    $this->actingAs($this->user)
        ->putJson('/menu/secciones', ['collapsed' => $collapsed])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect($this->user->fresh()->nav_collapsed)->toBe(['weekly']);
})->with([
    'desconocido' => [['weekly', 'pizza'], 'collapsed.1'],
    'repetido' => [['weekly', 'weekly'], 'collapsed.1'],
    'no es texto' => [['weekly', 3], 'collapsed.1'],
    'no es una lista' => ['weekly', 'collapsed'],
    'con claves' => [['a' => 'weekly'], 'collapsed'],
    'sin el campo' => [null, 'collapsed'],
]);

it('un colaborador externo también guarda las suyas', function () {
    $collaborator = User::factory()->collaborator()->create();

    $this->actingAs($collaborator)
        ->putJson('/menu/secciones', ['collapsed' => ['projects']])
        ->assertNoContent();

    expect($collaborator->fresh()->nav_collapsed)->toBe(['projects']);
});

it('un invitado no puede guardarlas', function () {
    $this->putJson('/menu/secciones', ['collapsed' => ['projects']])->assertUnauthorized();
});
