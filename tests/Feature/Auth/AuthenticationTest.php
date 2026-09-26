<?php

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('la pantalla de inicio de sesión se muestra', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

test('un usuario inicia sesión con credenciales correctas y va al inicio', function () {
    $user = userWithRole('employee');

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect('/');
});

test('el correo no distingue mayúsculas al iniciar sesión', function () {
    $user = userWithRole('employee', ['email' => 'ana@example.com']);

    $this->post(route('login.store'), [
        'email' => 'ANA@Example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('con una contraseña incorrecta no se inicia sesión y el mensaje está en español', function () {
    $user = userWithRole('employee');

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'contraseña-incorrecta',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
    expect(__('auth.failed'))->toContain('contraseña');
});

test('un usuario con 2FA pasa por el desafío antes de entrar', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->employee()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('un usuario puede cerrar sesión', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

test('el inicio de sesión tiene límite de intentos', function () {
    $user = userWithRole('employee');

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'contraseña-incorrecta',
    ])->assertTooManyRequests();
});

test('tras cinco intentos fallidos el sexto se bloquea', function () {
    $user = userWithRole('employee');

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'mal'])
            ->assertStatus(302);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();

    $this->assertGuest();
});

test('no existe el registro público', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com'])->assertNotFound();
    expect(Route::has('register'))->toBeFalse();
});

test('se registra un inicio de sesión correcto', function () {
    $user = userWithRole('employee');

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/130.0 Safari/537.36')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(LoginEvent::query()->count())->toBe(1);

    $event = LoginEvent::query()->sole();
    expect($event->succeeded)->toBeTrue()
        ->and($event->user_id)->toBe($user->id)
        ->and($event->email)->toBe($user->email)
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($event->user_agent)->toContain('Chrome');
});

test('se registra un inicio de sesión fallido sin guardar la contraseña', function () {
    $user = userWithRole('employee');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secreto-equivocado']);
    $this->post(route('login.store'), ['email' => 'nadie@example.com', 'password' => 'secreto-equivocado']);

    $events = LoginEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->succeeded)->toBeFalse()
        ->and($events[0]->user_id)->toBe($user->id)
        ->and($events[1]->user_id)->toBeNull()
        ->and($events[1]->email)->toBe('nadie@example.com')
        ->and(json_encode($events->toArray()))->not->toContain('secreto-equivocado');
});

test('un correo que no es texto no rompe el limitador de intentos', function (mixed $email) {
    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $email, 'password' => 'x'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->postJson(route('login.store'), ['email' => $email, 'password' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest();
})->with([
    'array' => [['a@example.com']],
    'vacío' => [null],
]);

test('con un correo inexistente también se comprueba la contraseña (tiempo constante)', function () {
    $dummyHash = Hash::make('hash-ficticio');
    Hash::shouldReceive('make')->andReturn($dummyHash);
    Hash::shouldReceive('check')->once()->with('secreto', $dummyHash)->andReturnFalse();

    $this->post(route('login.store'), ['email' => 'nadie@example.com', 'password' => 'secreto'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('al iniciar sesión se sincroniza la cookie de tema con la preferencia guardada', function () {
    $user = userWithRole('employee', ['theme_preference' => 'dark']);

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertCookie('appearance', 'dark', encrypted: false);

    expect($response->getCookie('appearance', decrypt: false)?->isHttpOnly())->toBeFalse();
});

test('un usuario desactivado que vuelve con «Recordarme» no queda registrado como acceso correcto', function () {
    $user = userWithRole('employee', ['is_active' => false, 'remember_token' => Str::random(60)]);

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');
    $recaller = $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->password);

    $this->withCookie($guard->getRecallerName(), $recaller)
        ->get('/')
        ->assertRedirect(route('login'));

    $this->assertGuest();

    $event = LoginEvent::query()->sole();
    expect($event->succeeded)->toBeFalse()->and($event->user_id)->toBe($user->id);
});
