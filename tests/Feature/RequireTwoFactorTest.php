<?php

use App\Models\Setting;
use App\Models\User;

test('sin require_2fa se entra sin 2FA', function () {
    $this->actingAs(userWithRole('employee'))->get('/')->assertOk();
});

test('con require_2fa activo, quien no tiene 2FA va a seguridad con un aviso', function () {
    Setting::set('require_2fa', true);

    $this->actingAs(userWithRole('employee'))
        ->get('/proyectos')
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', __('app.two_factor_required'));
});

test('con require_2fa activo, quien tiene 2FA confirmado entra con normalidad', function () {
    Setting::set('require_2fa', true);

    $user = User::factory()->withTwoFactor()->employee()->create();

    $this->actingAs($user)->get('/proyectos')->assertOk();
});

test('con require_2fa activo siguen disponibles los ajustes para poder activarlo', function () {
    Setting::set('require_2fa', true);

    $user = userWithRole('employee');

    $this->actingAs($user)->get('/ajustes/perfil')->assertOk();
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/ajustes/seguridad')
        ->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
});

test('con require_2fa activo, la búsqueda JSON responde 403 a quien no tiene 2FA', function () {
    Setting::set('require_2fa', true);

    $this->actingAs(userWithRole('employee'))->getJson('/buscar?q=pro')->assertForbidden();
});
