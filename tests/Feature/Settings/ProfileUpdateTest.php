<?php

use Illuminate\Support\Facades\Route;

test('la página de perfil se muestra', function () {
    $this->actingAs(userWithRole('employee'))
        ->get(route('profile.edit'))
        ->assertOk();
});

test('se puede actualizar el nombre y el correo', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Nombre Nuevo',
            'email' => 'nuevo@example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Nombre Nuevo')
        ->and($user->email)->toBe('nuevo@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('cambiar el correo exige la contraseña actual', function (?string $password, string $error) {
    $user = userWithRole('employee', ['email' => 'ana@example.com']);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), array_filter([
            'name' => $user->name,
            'email' => 'atacante@example.com',
            'current_password' => $password,
        ]))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['current_password' => $error]);

    expect($user->refresh()->email)->toBe('ana@example.com');
})->with([
    'sin contraseña' => [null, 'El campo contraseña actual es obligatorio.'],
    'con una contraseña incorrecta' => ['incorrecta', 'La contraseña no es correcta.'],
]);

test('cambiar solo el nombre no pide la contraseña', function () {
    $user = userWithRole('employee', ['email' => 'ana@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Ana Nueva', 'email' => ' Ana@Example.com '])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('Ana Nueva')
        ->and($user->email)->toBe('ana@example.com');
});

test('el correo se guarda en minúsculas y se puede seguir iniciando sesión', function () {
    $user = userWithRole('employee', ['email' => 'ana@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => '  Ana.Nueva@AudaxStudio.com ',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('ana.nueva@audaxstudio.com');

    $this->post(route('logout'));
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => 'Ana.Nueva@AudaxStudio.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('no se puede usar el correo de otra persona cambiando las mayúsculas', function () {
    userWithRole('employee', ['email' => 'luis@example.com']);
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'LUIS@example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('email');
});

test('si el correo no cambia, se mantiene verificado', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Otro Nombre', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('el perfil no permite tocar datos económicos, rol ni estado', function () {
    $user = userWithRole('employee', ['hourly_cost' => '20.00']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Pepa',
        'email' => $user->email,
        'hourly_cost' => '999.00',
        'default_hourly_rate' => '999.00',
        'is_active' => false,
        'department_id' => 999,
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->hourly_cost)->toBe('20.00')
        ->and($user->default_hourly_rate)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->department_id)->toBeNull();
});

test('los errores de validación del perfil están en español', function () {
    $this->actingAs(userWithRole('employee'))
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => '', 'email' => 'no-es-un-correo'])
        ->assertSessionHasErrors([
            'name' => 'El campo nombre es obligatorio.',
            'email' => 'El campo correo electrónico debe ser un correo electrónico válido.',
        ]);
});

test('no existe el borrado de la propia cuenta', function () {
    $user = userWithRole('employee');

    expect(Route::has('profile.destroy'))->toBeFalse();

    $this->actingAs($user)
        ->delete('/ajustes/perfil', ['password' => 'password'])
        ->assertMethodNotAllowed();

    expect($user->fresh())->not->toBeNull();
});
