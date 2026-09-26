<?php

use Inertia\Testing\AssertableInertia as Assert;

test('la pantalla de confirmar contraseña se muestra', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));
});

test('confirmar la contraseña exige haber iniciado sesión', function () {
    $this->get(route('password.confirm'))->assertRedirect(route('login'));
});
