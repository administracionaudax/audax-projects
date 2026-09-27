<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;

/*
| R1 · Matriz de permisos de D-044 en todas las rutas de R1 (páginas y exportaciones):
|   /informes · /informes/direccion · /informes/departamentos/{d} · /informes/personas/{u}
| Actores: invitado, admin, responsable de Diseño, responsable sin departamentos, gestor de un
| proyecto, empleada de Diseño y cliente. Los clientes y los invitados nunca entran.
*/

beforeEach(function () {
    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);

    $this->actors = [
        'admin' => User::factory()->admin()->create(),
        'responsable' => User::factory()->departmentManager()->create(['department_id' => $this->design->id]),
        'responsable_sin_departamento' => User::factory()->departmentManager()->create(),
        'gestor' => User::factory()->employee()->create(['department_id' => $this->marketing->id]),
        'empleada' => User::factory()->employee()->create(['department_id' => $this->design->id]),
        'cliente' => User::factory()->withRole(Role::Client)->create(),
    ];
    $this->design->managers()->attach($this->actors['responsable']);
    Project::factory()->create()->addMember($this->actors['gestor'], isManager: true);

    $this->colleague = User::factory()->employee()->create(['department_id' => $this->design->id]);
    $this->outsider = User::factory()->employee()->create(['department_id' => $this->marketing->id]);
});

/**
 * Rutas: [URL (con marcadores), estado esperado por actor]. {self} es quien mira.
 */
dataset('r1_rutas', [
    //                                                       admin  resp.  resp.0  gestor  empl.  cliente
    'índice' => ['/informes', [200, 200, 200, 200, 200, 302]],
    'dirección' => ['/informes/direccion', [200, 200, 403, 403, 403, 302]],
    'dirección (exportar)' => ['/informes/direccion?formato=csv&tabla=proyectos', [200, 200, 403, 403, 403, 302]],
    'departamento propio' => ['/informes/departamentos/{design}', [200, 200, 403, 403, 403, 302]],
    'departamento propio (exportar)' => ['/informes/departamentos/{design}?formato=xlsx', [200, 200, 403, 403, 403, 302]],
    'otro departamento' => ['/informes/departamentos/{marketing}', [200, 403, 403, 403, 403, 302]],
    'persona: la propia' => ['/informes/personas/{self}', [200, 200, 200, 200, 200, 302]],
    'persona: la propia (exportar)' => ['/informes/personas/{self}?formato=csv', [200, 200, 200, 200, 200, 302]],
    'persona: de Diseño' => ['/informes/personas/{colleague}', [200, 200, 403, 403, 403, 302]],
    'persona: de Diseño (exportar)' => ['/informes/personas/{colleague}?formato=xlsx', [200, 200, 403, 403, 403, 302]],
    'persona: de Marketing' => ['/informes/personas/{outsider}', [200, 403, 403, 403, 403, 302]],
]);

dataset('r1_actores', ['admin', 'responsable', 'responsable_sin_departamento', 'gestor', 'empleada', 'cliente']);

test('matriz de permisos de R1 (D-044)', function (string $path, array $expected, string $actor) {
    $index = array_search($actor, ['admin', 'responsable', 'responsable_sin_departamento', 'gestor', 'empleada', 'cliente'], true);
    $user = $this->actors[$actor];
    $url = strtr($path, [
        '{design}' => (string) $this->design->id,
        '{marketing}' => (string) $this->marketing->id,
        '{self}' => (string) $user->id,
        '{colleague}' => (string) $this->colleague->id,
        '{outsider}' => (string) $this->outsider->id,
    ]);

    $response = $this->actingAs($user)->get($url);
    $response->assertStatus($expected[$index]);

    if ($actor === 'cliente') {
        $response->assertRedirect(route('portal.home'));
    }
})->with('r1_rutas')->with('r1_actores');

test('sin sesión, todas las rutas de R1 llevan al login', function () {
    foreach (['/informes', '/informes/direccion', "/informes/departamentos/{$this->design->id}", "/informes/personas/{$this->colleague->id}", '/informes/direccion?formato=csv'] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('el informe de un usuario del portal no existe para nadie', function () {
    $this->actingAs($this->actors['admin'])
        ->get("/informes/personas/{$this->actors['cliente']->id}")
        ->assertForbidden();
});

test('un departamento o una persona que no existen dan 404', function () {
    $this->actingAs($this->actors['admin'])->get('/informes/departamentos/999999')->assertNotFound();
    $this->actingAs($this->actors['admin'])->get('/informes/personas/999999')->assertNotFound();
});

test('un usuario desactivado no ve ningún informe', function () {
    $user = $this->actors['admin'];
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/informes/direccion')->assertRedirect();
});
