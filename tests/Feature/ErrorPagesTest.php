<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Páginas de error en español con el tema de la app (UX-03, SPEC §19): 403, 404, 419… se pintan con
| la página Inertia «error», sin el texto en inglés de la excepción. Las peticiones JSON siguen
| recibiendo JSON.
*/

test('un 403 por rol (Spatie) muestra la página de error en español, sin el mensaje en inglés', function () {
    $employee = User::factory()->employee()->create();

    $response = $this->actingAs($employee)->get('/admin');

    $response->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
    expect($response->getContent())->not->toContain('User does not have the right roles');
});

test('un 403 de una política también', function () {
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee)
        ->get('/proyectos/nuevo')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
});

test('un 404 (modelo que no existe o ruta desconocida) muestra la página de error', function () {
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee)
        ->get('/proyectos/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));

    $this->get('/no-existe-esta-pagina')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
});

test('en una visita de Inertia el error llega como página de Inertia (no como HTML en un modal)', function () {
    $employee = User::factory()->employee()->create();
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($employee)
        ->get('/admin', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertForbidden()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'error')
        ->assertJsonPath('props.status', 403);
});

test('en una acción de Inertia dentro de la página (PATCH, recarga parcial) llega la respuesta de Laravel, en español, para que la página la trate sin salir', function () {
    $employee = User::factory()->employee()->create();
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

    $response = $this->actingAs($employee)->patch('/tareas/999999', ['title' => 'x'], $headers);
    $response->assertNotFound();
    expect($response->headers->has('X-Inertia'))->toBeFalse()
        ->and($response->getContent())->toContain('Página no encontrada');

    $response = $this->actingAs($employee)->get('/admin', [...$headers, 'X-Inertia-Partial-Component' => 'admin/index', 'X-Inertia-Partial-Data' => 'x']);
    $response->assertForbidden();
    expect($response->headers->has('X-Inertia'))->toBeFalse()
        ->and($response->getContent())->toContain('No tienes permiso para ver esta página.')
        ->not->toContain('User does not have the right roles');
});

test('las peticiones JSON siguen recibiendo JSON', function () {
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee)
        ->getJson('/proyectos/999999')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('una sesión caducada (419): página de error, o en una visita de Inertia vuelta atrás con un aviso', function () {
    // Los tests no comprueban el CSRF: se simula el token caducado con una ruta de prueba.
    Route::middleware('web')->post('/_prueba/419', fn () => throw new TokenMismatchException);
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $this->post('/_prueba/419')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 419));

    $this->from('/mis-tareas')
        ->post('/_prueba/419', [], ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertStatus(303)
        ->assertRedirect('/mis-tareas')
        ->assertInertiaFlash('toast.message', 'La página ha caducado. Vuelve a intentarlo.');
});
