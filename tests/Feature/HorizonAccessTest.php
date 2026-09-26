<?php

use App\Models\Setting;
use App\Models\User;

/*
| /horizon pasa por los mismos filtros que la app interna (config/horizon.php) y por la gate
| viewHorizon (solo admin).
*/

test('un invitado va al login', function () {
    $this->get('/horizon')->assertRedirect(route('login'));
});

test('un empleado no puede ver Horizon', function () {
    $this->actingAs(userWithRole('employee'))->get('/horizon')->assertForbidden();
});

test('un admin desactivado es expulsado', function () {
    $this->actingAs(userWithRole('admin', ['is_active' => false]))
        ->get('/horizon')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('con require_2fa activo, un admin sin 2FA va a seguridad', function () {
    Setting::set('require_2fa', true);

    $this->actingAs(userWithRole('admin'))
        ->get('/horizon')
        ->assertRedirect(route('security.edit'));

    $this->actingAs(userWithRole('admin'))
        ->getJson('/horizon/api/stats')
        ->assertForbidden();
});

test('con require_2fa activo, un admin con 2FA confirmado pasa los filtros', function () {
    Setting::set('require_2fa', true);

    $admin = User::factory()->withTwoFactor()->admin()->create();

    $this->actingAs($admin)
        ->get('/horizon')
        ->assertOk();
});
