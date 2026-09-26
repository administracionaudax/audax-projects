<?php

/*
|--------------------------------------------------------------------------
| Matriz de permisos (SPEC §5)
|--------------------------------------------------------------------------
| Cada ruta GET del contrato × cada tipo de usuario → código esperado.
| 302 de invitado = al login; 302 de cliente en rutas internas = a /portal;
| /ajustes/seguridad y /ajustes/sesiones piden confirmar la contraseña (302) a todos.
*/

const ACTORS = ['guest', 'admin', 'department_manager', 'employee', 'client'];

dataset('rutas', [
    //                       invitado  admin  resp.  empl.  cliente
    '/ (inicio)' => ['/', [302, 200, 200, 200, 302]],
    '/dashboard' => ['/dashboard', [302, 302, 302, 302, 302]],
    '/mis-tareas' => ['/mis-tareas', [302, 200, 200, 200, 302]],
    '/proyectos' => ['/proyectos', [302, 200, 200, 200, 302]],
    '/clientes' => ['/clientes', [302, 200, 200, 200, 302]],
    '/bolsas' => ['/bolsas', [302, 200, 200, 403, 302]],
    '/horas' => ['/horas', [302, 200, 200, 200, 302]],
    '/carga' => ['/carga', [302, 200, 200, 200, 302]],
    '/informes' => ['/informes', [302, 200, 200, 200, 302]],
    '/chat' => ['/chat', [302, 200, 200, 200, 302]],
    '/admin' => ['/admin', [302, 200, 403, 403, 302]],
    '/buscar' => ['/buscar?q=pro', [302, 200, 200, 200, 302]],
    '/portal' => ['/portal', [302, 403, 403, 403, 200]],
    '/ajustes/perfil' => ['/ajustes/perfil', [302, 200, 200, 200, 200]],
    '/ajustes/seguridad' => ['/ajustes/seguridad', [302, 302, 302, 302, 302]],
    '/ajustes/apariencia' => ['/ajustes/apariencia', [302, 200, 200, 200, 200]],
    '/ajustes/sesiones' => ['/ajustes/sesiones', [302, 302, 302, 302, 302]],
    '/health' => ['/health', [200, 200, 200, 200, 200]],
]);

dataset('actores', array_combine(ACTORS, array_map(fn (string $actor) => [$actor], ACTORS)));

test('matriz de permisos', function (string $path, array $expected, string $actor) {
    $status = $expected[array_search($actor, ACTORS, true)];

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get($path);

    $response->assertStatus($status);

    if ($status === 302 && $actor === 'guest') {
        $response->assertRedirect(route('login'));
    }

    if ($status === 302 && $actor === 'client' && ! str_starts_with($path, '/ajustes')) {
        $response->assertRedirect(route('portal.home'));
    }
})->with('rutas')->with('actores');

test('un cliente nunca entra en la aplicación interna: siempre acaba en su portal', function () {
    $client = userWithRole('client');

    foreach (['/', '/proyectos', '/bolsas', '/admin', '/horas'] as $path) {
        $this->actingAs($client)->get($path)->assertRedirect(route('portal.home'));
    }
});

test('/admin solo es para administración', function () {
    $this->actingAs(userWithRole('admin'))->get('/admin')->assertOk();

    foreach (['department_manager', 'employee'] as $role) {
        $this->actingAs(userWithRole($role))->get('/admin')->assertForbidden();
    }
});

test('/styleguide es pública cuando la configuración lo permite', function (string $actor) {
    config(['app.styleguide_public' => true]);

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $this->get('/styleguide')->assertOk();
})->with('actores');

test('/styleguide es solo para admin cuando no es pública', function (string $actor, int $status) {
    config(['app.styleguide_public' => false]);

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get('/styleguide')->assertStatus($status);

    if ($actor === 'guest') {
        $response->assertRedirect(route('login'));
    }
})->with([
    'invitado' => ['guest', 302],
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 403],
    'empleado' => ['employee', 403],
    'cliente' => ['client', 403],
]);
