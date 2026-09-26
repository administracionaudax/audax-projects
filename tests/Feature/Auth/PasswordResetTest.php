<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

test('la respuesta es la misma exista o no el correo, y aunque el envío esté limitado', function () {
    Notification::fake();

    $user = userWithRole('employee');

    $responses = [
        'existe' => $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]),
        'limitado por el broker' => $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]),
        'no existe' => $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nadie@example.com']),
    ];

    foreach ($responses as $response) {
        $response->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);

    $this->postJson(route('password.email'), ['email' => 'otro@example.com'])
        ->assertOk()
        ->assertExactJson(['message' => __('passwords.sent')]);
});

test('pedir enlaces de recuperación está limitado por IP', function () {
    Notification::fake();

    foreach (range(1, 5) as $i) {
        $this->post(route('password.email'), ['email' => "persona{$i}@example.com"])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'persona6@example.com'])->assertTooManyRequests();
});

test('pedir enlaces de recuperación está limitado por correo, desde cualquier IP', function () {
    Notification::fake();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
            ->post(route('password.email'), ['email' => 'Victima@example.com'])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])
        ->post(route('password.email'), ['email' => 'victima@example.com'])
        ->assertTooManyRequests();
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

test('restablecer la contraseña cierra todas las sesiones abiertas del usuario', function () {
    config(['session.driver' => 'database']);

    $user = userWithRole('employee', ['remember_token' => Str::random(60)]);
    $other = userWithRole('employee');
    insertSession($user);
    insertSession($user);
    $foreign = insertSession($other);
    $oldRememberToken = $user->remember_token;

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'nueva-contraseña',
        'password_confirmation' => 'nueva-contraseña',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe($oldRememberToken);
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
