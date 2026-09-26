<?php

use App\Models\Client;

/*
| Matriz de permisos de clientes (ClientPolicy, D-021, D-022): los ven todos los internos; los crean,
| editan, desactivan y reactivan admins y responsables. Un cliente nunca entra en la app interna.
*/

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Hoteles Mediterráneo']);
    $this->inactive = Client::factory()->inactive()->create(['name' => 'Bodegas Lur']);
    $this->snapshot = fn (): array => Client::query()->orderBy('id')->get(['id', 'name', 'is_active'])->toArray();
});

dataset('client_pages', [
    'listado' => [fn () => '/clientes'],
    'ficha' => [fn () => "/clientes/{$this->client->id}"],
    'opciones' => [fn () => '/clientes/opciones'],
]);

dataset('client_actions', [
    'crear' => [fn () => ['post', '/clientes', ['name' => 'Cliente nuevo']]],
    'editar' => [fn () => ['put', "/clientes/{$this->client->id}", ['name' => 'Cambiado']]],
    'desactivar' => [fn () => ['post', "/clientes/{$this->client->id}/desactivar", []]],
    'reactivar' => [fn () => ['post', "/clientes/{$this->inactive->id}/reactivar", []]],
]);

test('las páginas de clientes las ven todos los internos', function (string $url, string $actor, int $status) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get($url)->assertStatus($status);

    if ($actor === 'guest') {
        $response->assertRedirect(route('login'));
    }

    if ($actor === 'client') {
        $response->assertRedirect(route('portal.home'));
    }
})->with('client_pages')->with([
    'invitado' => ['guest', 302],
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 200],
    'empleado' => ['employee', 200],
    'cliente' => ['client', 302],
]);

test('crean, editan, desactivan y reactivan admins y responsables; nadie más', function (array $request, string $actor, int $status) {
    [$method, $url, $payload] = $request;

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $before = ($this->snapshot)();

    $response = $this->call(strtoupper($method), $url, $payload)->assertStatus($status);

    if (in_array($actor, ['admin', 'department_manager'], true)) {
        $response->assertSessionHasNoErrors();
        expect(($this->snapshot)())->not->toBe($before);
    } else {
        expect(($this->snapshot)())->toBe($before);
    }

    if ($actor === 'guest') {
        $response->assertRedirect(route('login'));
    }

    if ($actor === 'client') {
        $response->assertRedirect(route('portal.home'));
    }
})->with('client_actions')->with([
    'invitado' => ['guest', 302],
    'admin' => ['admin', 302],
    'responsable' => ['department_manager', 302],
    'empleado' => ['employee', 403],
    'cliente' => ['client', 302],
]);
