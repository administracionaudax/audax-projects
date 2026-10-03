<?php

use App\Domain\Notifications\NotificationPreferences;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| /ajustes/notificaciones (SPEC §13, D-073): cada persona interna elige qué avisos recibe y por qué
| canal, y si quiere el resumen diario. Solo internos (grupo internal): un cliente va a su portal.
| Lo que se guarda lo decide NotificationPreferences::update: nada obligatorio ni que no se ofrezca.
*/

beforeEach(function () {
    $this->employee = User::factory()->employee()->create();
    $this->preferences = app(NotificationPreferences::class);
    $this->url = '/ajustes/notificaciones';
});

test('las rutas son /ajustes/notificaciones con nombres en inglés', function () {
    expect(route('notification-settings.edit', absolute: false))->toBe('/ajustes/notificaciones')
        ->and(route('notification-settings.update', absolute: false))->toBe('/ajustes/notificaciones');
});

test('cada persona interna ve la página con sus eventos', function (string $role, array $groups) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/notifications')
            ->where('settings.daily_digest', false)
            ->where('settings.push_available', false)
            ->where('settings.groups', fn ($value): bool => array_column(collect($value)->all(), 'key') === $groups));
})->with([
    'admin' => ['admin', ['tasks', 'time', 'hour_banks', 'absences', 'chat', 'reports', 'system']],
    'responsable' => ['departmentManager', ['tasks', 'time', 'hour_banks', 'absences', 'chat', 'reports']],
    // Cualquiera puede programar envíos de informes: el aviso de pausa (D-141) es de todos.
    'empleado' => ['employee', ['tasks', 'time', 'absences', 'chat', 'reports']],
]);

test('un cliente no entra: va a su portal y no se guarda nada', function () {
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    $this->actingAs($client)->get($this->url)->assertRedirect(route('portal.home'));
    $this->actingAs($client)
        ->put($this->url, ['events' => ['task.due' => ['email' => false]], 'daily_digest' => true])
        ->assertRedirect(route('portal.home'));
    $this->actingAs($client)->getJson($this->url)->assertForbidden();
    $this->actingAs($client)
        ->putJson($this->url, ['events' => [], 'daily_digest' => true])
        ->assertForbidden();

    expect($client->refresh()->notification_preferences)->toBeNull();
});

test('un invitado va al login', function () {
    $this->get($this->url)->assertRedirect(route('login'));
    $this->put($this->url, ['events' => [], 'daily_digest' => true])->assertRedirect(route('login'));
});

test('una persona desactivada no entra', function () {
    $inactive = User::factory()->employee()->inactive()->create();

    $this->actingAs($inactive)->get($this->url)->assertRedirect(route('login'));
});

test('la página hace pocas consultas: las preferencias salen del catálogo y del usuario', function () {
    $this->actingAs($this->employee)->get($this->url)->assertOk();
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        $count++;
    });

    $this->actingAs($this->employee)->get($this->url)->assertOk();

    // Gestor de algún proyecto (una vez) y las props compartidas (temporizador, campana…).
    expect($count)->toBeLessThanOrEqual(6);
});

test('la página lleva cada evento con su texto, sus canales y si es obligatorio', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.groups.0.label', 'Tareas')
            ->where('settings.groups.0.events.4', [
                'kind' => 'task.due',
                'label' => 'Tareas que vencen',
                'description' => 'Tus tareas que vencen mañana o ya han vencido.',
                'mandatory' => false,
                'channels' => [
                    'app' => ['offered' => true, 'enabled' => true],
                    'email' => ['offered' => true, 'enabled' => true],
                    'push' => ['offered' => false, 'enabled' => false],
                ],
            ])
            ->where('settings.groups.6.key', 'system')
            ->where('settings.groups.6.events.1.kind', 'system.disk_space')
            ->where('settings.groups.6.events.1.mandatory', true));
});

test('guardar cambia solo lo que difiere del catálogo, activa el resumen y avisa', function () {
    $this->actingAs($this->employee)
        ->from($this->url)
        ->put($this->url, [
            'events' => [
                'task.due' => ['app' => true, 'email' => false],
                'task.assigned' => ['app' => true, 'email' => true, 'push' => false],
                'time.week_reminder' => ['email' => true],
            ],
            'daily_digest' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect($this->url)
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Preferencias de notificación guardadas.');

    $this->employee->refresh();

    expect($this->employee->notification_preferences)->toBe([
        'events' => [
            'task.due' => ['email' => false],
            'task.assigned' => ['email' => true],
            'time.week_reminder' => ['email' => true],
        ],
        'daily_digest' => true,
    ])->and($this->preferences->channelsFor($this->employee, 'task.due'))->toBe(['database']);

    // Al volver a la página se ve lo guardado.
    $this->actingAs($this->employee)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.daily_digest', true)
            ->where('settings.groups.0.events.4.channels.email.enabled', false)
            ->where('settings.groups.0.events.0.channels.email.enabled', true));
});

test('volver al valor por defecto borra lo guardado y desactivar el resumen también se guarda', function () {
    $this->preferences->update($this->employee, ['task.due' => ['email' => false]], true);

    $this->actingAs($this->employee)
        ->put($this->url, ['events' => ['task.due' => ['email' => true]], 'daily_digest' => false])
        ->assertSessionHasNoErrors();

    expect($this->employee->refresh()->notification_preferences)->toBe(['events' => [], 'daily_digest' => false]);
});

test('los obligatorios, lo que no se ofrece y lo desconocido se ignoran', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put($this->url, [
            'events' => [
                'system.disk_space' => ['app' => false, 'email' => false],
                'system.backup_failed' => ['email' => false],
                'reports.weekly_digest' => ['push' => true],
                'no.existe' => ['app' => true],
            ],
            'daily_digest' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->notification_preferences)->toBe(['events' => [], 'daily_digest' => false])
        ->and($this->preferences->channelsFor($admin, 'system.disk_space'))->toBe(['database', 'mail']);

    // A una empleada no se le ofrecen los avisos de bolsas: no se guardan.
    $this->actingAs($this->employee)
        ->put($this->url, ['events' => ['hour_bank.threshold' => ['email' => false]], 'daily_digest' => false])
        ->assertSessionHasNoErrors();

    expect($this->employee->refresh()->notification_preferences['events'])->toBe([]);
});

test('admite los valores 0 y 1 del formulario como booleanos', function () {
    $this->actingAs($this->employee)
        ->put($this->url, ['events' => ['task.due' => ['email' => '0'], 'task.assigned' => ['email' => 1]], 'daily_digest' => '1'])
        ->assertSessionHasNoErrors();

    expect($this->employee->refresh()->notification_preferences)->toBe([
        'events' => ['task.due' => ['email' => false], 'task.assigned' => ['email' => true]],
        'daily_digest' => true,
    ]);
});

test('valida la forma de lo que se envía y no guarda nada si está mal', function (array $payload, string $error) {
    $this->preferences->update($this->employee, ['task.due' => ['email' => false]], false);
    $before = $this->employee->refresh()->notification_preferences;

    $this->actingAs($this->employee)
        ->from($this->url)
        ->put($this->url, $payload)
        ->assertRedirect($this->url)
        ->assertSessionHasErrors($error);

    expect($this->employee->refresh()->notification_preferences)->toBe($before);
})->with([
    'sin resumen diario' => [['events' => []], 'daily_digest'],
    'resumen diario que no es sí o no' => [['events' => [], 'daily_digest' => 'quizás'], 'daily_digest'],
    'sin eventos' => [['daily_digest' => true], 'events'],
    'eventos que no son una lista' => [['events' => 'todos', 'daily_digest' => true], 'events'],
    'canales que no son una lista' => [['events' => ['task.due' => 'email'], 'daily_digest' => true], 'events'],
    'canales sin nombre' => [['events' => ['task.due' => [true]], 'daily_digest' => true], 'events'],
    'un canal que no existe' => [['events' => ['task.due' => ['fax' => true]], 'daily_digest' => true], 'events'],
    'un valor que no es sí o no' => [['events' => ['task.due' => ['email' => 'sí']], 'daily_digest' => true], 'events'],
    'un valor vacío' => [['events' => ['task.due' => ['email' => null]], 'daily_digest' => true], 'events'],
]);

test('no admite más eventos de los razonables', function () {
    $events = [];
    foreach (range(1, 101) as $n) {
        $events["evento.{$n}"] = ['app' => true];
    }

    $this->actingAs($this->employee)
        ->put($this->url, ['events' => $events, 'daily_digest' => false])
        ->assertSessionHasErrors('events');
});

test('los errores de validación se explican en español', function () {
    $this->actingAs($this->employee)
        ->put($this->url, ['events' => ['task.due' => ['fax' => true]], 'daily_digest' => 'x'])
        ->assertSessionHasErrors([
            'events' => 'Los canales solo pueden ser «En la app», «Email» y «Avisos del navegador».',
            'daily_digest' => 'El campo resumen diario debe ser verdadero o falso.',
        ]);

    $this->actingAs($this->employee)
        ->put($this->url, ['events' => ['task.due' => ['email' => 'sí']], 'daily_digest' => true])
        ->assertSessionHasErrors(['events' => 'Cada canal tiene que estar activado o desactivado.']);
});

test('la búsqueda global lleva a las preferencias de notificación', function (string $query) {
    $results = $this->actingAs($this->employee)
        ->getJson('/buscar?q='.urlencode($query))
        ->assertOk()
        ->json('results');

    expect(collect($results)->firstWhere('id', 'notification-settings.edit'))->toBe([
        'type' => 'page',
        'id' => 'notification-settings.edit',
        'title' => 'Preferencias de notificación',
        'subtitle' => 'Ajustes',
        'url' => '/ajustes/notificaciones',
    ]);
})->with(['preferencias', 'notificacion', 'resumen diario', 'EMAIL']);
