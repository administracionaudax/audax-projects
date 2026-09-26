<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
