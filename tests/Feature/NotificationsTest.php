<?php

use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Notificaciones en la app (SPEC §13, D-037): campana y /notificaciones.
*/

beforeEach(function () {
    $this->user = User::factory()->employee()->create();
    $this->notify = function (User $user, array $data = []): string {
        $id = (string) Str::uuid();
        $user->notifications()->create([
            'id' => $id,
            'type' => AppNotification::class,
            'data' => [
                'kind' => 'task.assigned',
                'title' => 'Te han asignado «Maquetar»',
                'body' => null,
                'url' => '/proyectos/1/tareas?tarea=2',
                'icon' => 'user-plus',
                ...$data,
            ],
        ]);

        return $id;
    };
});

test('solo los internos autenticados ven sus notificaciones', function () {
    $this->get('/notificaciones')->assertRedirect(route('login'));
    $this->actingAs(userWithRole('client'))->get('/notificaciones')->assertRedirect('/portal');
    $this->actingAs(userWithRole('client'))->getJson('/notificaciones/recientes')->assertForbidden();
});

test('lista las mías, con filtro de sin leer y el recuento', function () {
    ($this->notify)($this->user, ['title' => 'Primera']);
    $read = ($this->notify)($this->user, ['title' => 'Leída']);
    $this->user->notifications()->whereKey($read)->update(['read_at' => now()]);
    ($this->notify)(User::factory()->create(), ['title' => 'De otra persona']);

    $this->actingAs($this->user)
        ->get('/notificaciones')
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index')
            ->where('unread', 1)
            ->where('filter', 'all')
            ->has('items.data', 2)
            ->where('items.data.0.data.kind', 'task.assigned'));

    $this->actingAs($this->user)
        ->get('/notificaciones?filtro=sin-leer')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter', 'unread')
            ->has('items.data', 1)
            ->where('items.data.0.data.title', 'Primera'));
});

test('la campana recibe las recientes y el recuento en JSON', function () {
    foreach (range(1, 10) as $i) {
        ($this->notify)($this->user, ['title' => "Aviso {$i}"]);
    }

    $this->actingAs($this->user)
        ->getJson('/notificaciones/recientes')
        ->assertOk()
        ->assertJsonPath('unread', 10)
        ->assertJsonCount(8, 'notifications')
        ->assertJsonStructure(['notifications' => [['id', 'data' => ['kind', 'title', 'body', 'url', 'icon'], 'read_at', 'created_at']]]);
});

test('abrir una notificación la marca como leída y lleva a su destino', function () {
    $id = ($this->notify)($this->user);

    $this->actingAs($this->user)
        ->post("/notificaciones/{$id}/abrir")
        ->assertRedirect('/proyectos/1/tareas?tarea=2');

    expect($this->user->notifications()->whereKey($id)->first()->read_at)->not->toBeNull();
});

test('nunca redirige fuera de la app (redirección abierta)', function (string $url) {
    $id = ($this->notify)($this->user, ['url' => $url]);

    $this->actingAs($this->user)
        ->post("/notificaciones/{$id}/abrir")
        ->assertRedirect(route('notifications.index'));
})->with(['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)']);

test('no se pueden abrir ni marcar las notificaciones de otra persona', function () {
    $other = User::factory()->employee()->create();
    $id = ($this->notify)($other);

    $this->actingAs($this->user)->post("/notificaciones/{$id}/abrir")->assertNotFound();
    $this->actingAs($this->user)->post("/notificaciones/{$id}/leida")->assertNotFound();

    expect($other->notifications()->whereKey($id)->first()->read_at)->toBeNull();
});

test('marcar todas como leídas solo afecta a las mías', function () {
    ($this->notify)($this->user);
    ($this->notify)($this->user);
    $other = User::factory()->employee()->create();
    ($this->notify)($other);

    $this->actingAs($this->user)->post('/notificaciones/leidas')->assertRedirect();

    expect($this->user->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

test('las props compartidas llevan el recuento de sin leer', function () {
    ($this->notify)($this->user);

    $this->actingAs($this->user)
        ->get('/notificaciones')
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 1));
});
