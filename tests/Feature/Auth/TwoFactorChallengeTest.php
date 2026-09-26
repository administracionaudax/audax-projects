<?php

use App\Models\LoginEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('el desafío 2FA redirige al login si no hay un inicio de sesión pendiente', function () {
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));
});

test('el desafío 2FA se muestra tras la contraseña', function () {
    $user = User::factory()->withTwoFactor()->employee()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
});

test('con un código de recuperación válido se completa el inicio de sesión y se registra', function () {
    $user = User::factory()->withTwoFactor()->employee()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertGuest();
    expect(LoginEvent::query()->count())->toBe(0);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(LoginEvent::query()->where('succeeded', true)->count())->toBe(1);
});

test('con un código incorrecto no se inicia sesión', function () {
    $user = User::factory()->withTwoFactor()->employee()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['code' => '000000']);

    $this->assertGuest();
});
