<?php

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('un usuario desactivado no puede iniciar sesión', function () {
    $user = User::factory()->inactive()->employee()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

    $this->assertGuest();

    $event = LoginEvent::query()->sole();
    expect($event->succeeded)->toBeFalse()->and($event->user_id)->toBe($user->id);
});

test('un usuario desactivado con contraseña incorrecta recibe el mensaje genérico', function () {
    $user = User::factory()->inactive()->employee()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'otra'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('si se desactiva a un usuario con la sesión abierta, se le expulsa', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->get('/')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

    $this->assertGuest();
});

test('un usuario desactivado tampoco llega a sus ajustes ni al portal', function (string $role, string $path) {
    $user = userWithRole($role, ['is_active' => false]);

    $this->actingAs($user)->get($path)->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'ajustes de empleado' => ['employee', '/ajustes/perfil'],
    'portal de cliente' => ['client', '/portal'],
]);

test('un usuario desactivado no puede gestionar su 2FA en las rutas de Fortify', function () {
    $user = userWithRole('employee', ['is_active' => false]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('app.account_inactive')]);

    $this->assertGuest();
    expect($user->refresh()->two_factor_secret)->toBeNull();
});

test('al desactivar a un usuario se cierran sus sesiones y su «Recordarme»', function () {
    config(['session.driver' => 'database']);

    $user = userWithRole('employee', ['remember_token' => 'token-anterior']);
    $other = userWithRole('employee');
    insertSession($user);
    $foreign = insertSession($other);

    $user->update(['name' => 'Sin cambios de estado']);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $user->update(['is_active' => false]);

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe('token-anterior');
});

test('una petición JSON de un usuario desactivado recibe 401', function () {
    $user = userWithRole('employee', ['is_active' => false]);

    $this->actingAs($user)->getJson('/buscar?q=ab')->assertUnauthorized();
});
