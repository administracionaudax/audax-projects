<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('la página de seguridad se muestra tras confirmar la contraseña', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $this->actingAs(userWithRole('employee'))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false));
});

test('la página de seguridad pide confirmar la contraseña', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $this->actingAs(userWithRole('employee'))
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('la página de seguridad funciona sin 2FA si la feature está desactivada', function () {
    config(['fortify.features' => []]);

    $this->actingAs(userWithRole('employee'))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'));
});

test('se puede cambiar la contraseña', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('nueva-contraseña', $user->refresh()->password))->toBeTrue();
});

test('para cambiar la contraseña hay que dar la actual', function () {
    $this->actingAs(userWithRole('employee'))
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'incorrecta',
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])
        ->assertSessionHasErrors(['current_password' => 'La contraseña no es correcta.'])
        ->assertRedirect(route('security.edit'));
});

test('cambiar la contraseña cierra las demás sesiones y el «Recordarme», pero no la actual', function () {
    config(['session.driver' => 'database']);

    $user = userWithRole('employee', ['remember_token' => Str::random(60)]);
    $other = userWithRole('employee');
    $current = insertSession($user);
    insertSession($user);
    $foreign = insertSession($other);
    $oldRememberToken = $user->remember_token;

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current])
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe($oldRememberToken);

    // La sesión actual sigue abierta con la contraseña nueva (AuthenticateSession no la expulsa).
    $this->withCookie(config('session.cookie'), $current)
        ->get(route('home'))
        ->assertOk();
    $this->assertAuthenticatedAs($user);
});
