<?php

use App\Models\LoginEvent;
use App\Models\User;

test('un usuario desactivado no puede iniciar sesión', function () {
    $user = User::factory()->inactive()->employee()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

    $this->assertGuest();

    $event = LoginEvent::query()->sole();
    expect($event->succeeded)->toBeFalse()->and($event->user_id)->toBe($user->id);
});

test('un usuario desactivado con contraseña incorrecta recibe el mensaje genérico', function () {
    $user = User::factory()->inactive()->employee()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'otra'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('si se desactiva a un usuario con la sesión abierta, se le expulsa', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->get('/')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

    $this->assertGuest();
});

test('un usuario desactivado tampoco llega a sus ajustes ni al portal', function (string $role, string $path) {
    $user = userWithRole($role, ['is_active' => false]);

    $this->actingAs($user)->get($path)->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'ajustes de empleado' => ['employee', '/ajustes/perfil'],
    'portal de cliente' => ['client', '/portal'],
]);

test('una petición JSON de un usuario desactivado recibe 401', function () {
    $user = userWithRole('employee', ['is_active' => false]);

    $this->actingAs($user)->getJson('/buscar?q=ab')->assertUnauthorized();
});
