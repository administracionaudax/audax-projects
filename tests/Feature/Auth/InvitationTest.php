<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Aceptar una invitación de alta: enlace de 7 días con el broker «invitations» (SPEC §14).
*/

beforeEach(function () {
    $this->invited = User::factory()->employee()->create(['email' => 'nueva@audaxstudio.com', 'password' => 'desconocida-123']);
    $this->token = (string) str(InvitationController::urlFor($this->invited))->after('/invitacion/')->before('?');
    $this->accept = fn (array $overrides = []) => $this->post('/invitacion', [
        'token' => $this->token,
        'email' => 'nueva@audaxstudio.com',
        'password' => 'Una-Clave-Segura-2026',
        'password_confirmation' => 'Una-Clave-Segura-2026',
        ...$overrides,
    ]);
});

test('el enlace muestra el formulario con el email y las reglas de contraseña', function () {
    $this->get(InvitationController::urlFor($this->invited))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('email', 'nueva@audaxstudio.com')
            ->has('token')
            ->has('passwordRules'));
});

test('aceptar fija la contraseña y lleva al login, aunque hayan pasado 6 días', function () {
    $this->travel(6)->days();

    ($this->accept)()->assertRedirect(route('login'))->assertSessionHas('status');

    expect(Hash::check('Una-Clave-Segura-2026', $this->invited->fresh()->password))->toBeTrue();
});

test('el enlace caduca a los 7 días y es de un solo uso', function () {
    ($this->accept)()->assertRedirect(route('login'));
    ($this->accept)(['password' => 'Otra-Clave-Segura-2026', 'password_confirmation' => 'Otra-Clave-Segura-2026'])
        ->assertSessionHasErrors('email');

    $other = User::factory()->employee()->create(['email' => 'otra@audaxstudio.com']);
    $url = InvitationController::urlFor($other);
    $this->travel(8)->days();

    $this->post('/invitacion', [
        'token' => (string) str($url)->after('/invitacion/')->before('?'),
        'email' => 'otra@audaxstudio.com',
        'password' => 'Una-Clave-Segura-2026',
        'password_confirmation' => 'Una-Clave-Segura-2026',
    ])->assertSessionHasErrors('email');
});

test('rechaza tokens falsos y cuentas desactivadas con el mismo mensaje', function () {
    ($this->accept)(['token' => 'falso'])->assertSessionHasErrors(['email' => __('app.invitation_invalid')]);

    $this->invited->update(['is_active' => false]);
    ($this->accept)()->assertSessionHasErrors(['email' => __('app.invitation_invalid')]);
});

test('exige una contraseña válida y confirmada', function () {
    ($this->accept)(['password_confirmation' => 'distinta'])->assertSessionHasErrors('password');
});

test('una persona con sesión iniciada no ve el formulario', function () {
    $this->actingAs(User::factory()->employee()->create())
        ->get(InvitationController::urlFor($this->invited))
        ->assertRedirect();
});

test('un token de «he olvidado la contraseña» no vale como invitación (SEG-01: 60 minutos, no 7 días)', function () {
    $user = User::factory()->employee()->create(['email' => 'ana@audaxstudio.com', 'password' => 'desconocida-123']);
    $token = Password::broker('users')->createToken($user);

    $this->travel(3)->days();
    expect(Password::broker('users')->tokenExists($user, $token))->toBeFalse();

    $this->post('/invitacion', [
        'token' => $token,
        'email' => 'ana@audaxstudio.com',
        'password' => 'Una-Clave-Segura-2026',
        'password_confirmation' => 'Una-Clave-Segura-2026',
    ])->assertSessionHasErrors(['email' => __('app.invitation_invalid')]);

    expect(Hash::check('Una-Clave-Segura-2026', $user->fresh()->password))->toBeFalse();
});

test('ni siquiera un token de restablecimiento recién creado vale en /invitacion', function () {
    $user = User::factory()->employee()->create(['email' => 'ana@audaxstudio.com']);
    $token = Password::broker('users')->createToken($user);

    $this->post('/invitacion', [
        'token' => $token,
        'email' => 'ana@audaxstudio.com',
        'password' => 'Una-Clave-Segura-2026',
        'password_confirmation' => 'Una-Clave-Segura-2026',
    ])->assertSessionHasErrors('email');
});

test('pedir «he olvidado la contraseña» con el email de un invitado no invalida su invitación (SEG-01)', function () {
    $this->travel(2)->minutes();
    $this->post('/forgot-password', ['email' => 'nueva@audaxstudio.com'])->assertSessionHasNoErrors();

    expect(DB::table('password_reset_tokens')->where('email', 'nueva@audaxstudio.com')->exists())->toBeTrue();

    ($this->accept)()->assertSessionHasNoErrors()->assertRedirect(route('login'));
    expect(Hash::check('Una-Clave-Segura-2026', $this->invited->fresh()->password))->toBeTrue();
});
