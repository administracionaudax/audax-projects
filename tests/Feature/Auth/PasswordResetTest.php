<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('la pantalla de recuperar contraseña se muestra', function () {
    $this->get(route('password.request'))->assertOk();
});

test('se puede pedir el enlace de recuperación', function () {
    Notification::fake();

    $user = userWithRole('employee');

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentTo($user, ResetPassword::class);
});

test('la pantalla de nueva contraseña se muestra con un token', function () {
    Notification::fake();

    $user = userWithRole('employee');

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) {
        $this->get(route('password.reset', $notification->token))->assertOk();

        return true;
    });
});

test('la contraseña se restablece con un token válido', function () {
    Notification::fake();

    $user = userWithRole('employee');

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        expect(Hash::check('nueva-contraseña', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('el token de restablecimiento es de un solo uso', function () {
    $user = userWithRole('employee');
    $token = Password::broker()->createToken($user);

    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nueva-contraseña',
        'password_confirmation' => 'nueva-contraseña',
    ];

    $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
    $this->post(route('password.update'), [...$payload, 'password' => 'otra', 'password_confirmation' => 'otra'])
        ->assertSessionHasErrors('email');

    expect(Hash::check('nueva-contraseña', User::query()->findOrFail($user->id)->password))->toBeTrue();
});

test('la contraseña no se restablece con un token inválido', function () {
    $user = userWithRole('employee');

    $this->post(route('password.update'), [
        'token' => 'token-invalido',
        'email' => $user->email,
        'password' => 'nueva-contraseña',
        'password_confirmation' => 'nueva-contraseña',
    ])->assertSessionHasErrors(['email' => __('passwords.token')]);
});
