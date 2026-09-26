<?php

use App\Models\Department;
use App\Models\User;

/**
 * @return list<string>
 */
function searchTitles(string $query, ?User $user = null): array
{
    $response = test()->actingAs($user ?? userWithRole('employee'))
        ->getJson('/buscar?q='.urlencode($query))
        ->assertOk();

    return array_column($response->json('results'), 'title');
}

test('la búsqueda exige haber iniciado sesión', function () {
    $this->getJson('/buscar?q=pro')->assertUnauthorized();
    $this->get('/buscar?q=pro')->assertRedirect(route('login'));
});

test('un cliente no puede usar la búsqueda interna', function () {
    $this->actingAs(userWithRole('client'))->getJson('/buscar?q=pro')->assertForbidden();
});

test('hacen falta al menos 2 caracteres', function (string $query) {
    $this->actingAs(userWithRole('admin'))
        ->getJson('/buscar?q='.urlencode($query))
        ->assertOk()
        ->assertExactJson(['results' => []]);
})->with(['', 'a', ' p ']);

test('los resultados siguen el contrato {type, id, title, subtitle, url}', function () {
    $this->actingAs(userWithRole('admin'))
        ->getJson('/buscar?q=proyectos')
        ->assertOk()
        ->assertJsonPath('results.0', [
            'type' => 'page',
            'id' => 'projects.index',
            'title' => 'Proyectos',
            'subtitle' => 'Proyectos y tareas',
            'url' => '/proyectos',
        ]);
});

test('un empleado no ve Bolsas ni Administración', function () {
    $employee = userWithRole('employee');

    expect(searchTitles('bolsas', $employee))->not->toContain('Bolsas')
        ->and(searchTitles('administ', $employee))->not->toContain('Administración')
        ->and(searchTitles('horas', $employee))->toContain('Horas');
});

test('un responsable ve Bolsas pero no Administración', function () {
    $manager = userWithRole('department_manager');

    expect(searchTitles('bolsas', $manager))->toContain('Bolsas')
        ->and(searchTitles('administ', $manager))->not->toContain('Administración');
});

test('el admin ve Bolsas y Administración, sin importar acentos ni mayúsculas', function () {
    $admin = userWithRole('admin');

    expect(searchTitles('BOLSAS', $admin))->toContain('Bolsas')
        ->and(searchTitles('administracion', $admin))->toContain('Administración');
});

test('devuelve personas internas activas por nombre o correo', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    User::factory()->employee()->inDepartment($design)->create(['name' => 'Lucía Martín', 'email' => 'lucia@audaxstudio.com']);
    User::factory()->employee()->inactive()->create(['name' => 'Lucía Inactiva']);
    User::factory()->client()->create(['name' => 'Lucía Cliente']);

    $response = $this->actingAs(userWithRole('employee'))
        ->getJson('/buscar?q=luc')
        ->assertOk();

    $people = collect($response->json('results'))->where('type', 'person')->values();

    expect($people)->toHaveCount(1)
        ->and($people[0]['title'])->toBe('Lucía Martín')
        ->and($people[0]['subtitle'])->toBe('Diseño · lucia@audaxstudio.com')
        ->and(searchTitles('LUCIA@AUDAX'))->toContain('Lucía Martín');
});

test('la búsqueda de personas trata % y _ como texto', function () {
    User::factory()->employee()->create(['name' => 'Pedro Pérez']);

    expect(searchTitles('%%'))->toBe([])
        ->and(searchTitles('__'))->toBe([]);
});

test('devuelve como máximo 20 resultados', function () {
    User::factory()->employee()->count(30)->sequence(fn ($sequence) => ['name' => 'Persona '.$sequence->index])->create();

    expect(searchTitles('persona'))->toHaveCount(20);

    $this->actingAs(userWithRole('employee'))->getJson('/buscar?q=persona&limit=50')->assertUnprocessable();
});
