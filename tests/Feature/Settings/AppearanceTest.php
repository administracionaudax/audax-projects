<?php

use Inertia\Testing\AssertableInertia as Assert;

test('la página de apariencia se muestra', function () {
    $this->actingAs(userWithRole('employee'))
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
});

test('cambiar el tema lo guarda en el usuario y pone la cookie un año', function (string $theme) {
    $user = userWithRole('employee');

    $response = $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['theme' => $theme])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('appearance.edit'))
        ->assertCookie('appearance', $theme, encrypted: false);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'appearance');

    expect($user->refresh()->theme_preference)->toBe($theme)
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(360)->getTimestamp())
        // El JS la actualiza al cambiar de tema (use-appearance.tsx): no puede ser HttpOnly.
        ->and($cookie->isHttpOnly())->toBeFalse();
})->with(['light', 'dark', 'system']);

test('el tema solo admite claro, oscuro o sistema', function (mixed $theme) {
    $user = userWithRole('employee', ['theme_preference' => 'light']);

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->patch(route('appearance.update'), ['theme' => $theme])
        ->assertSessionHasErrors('theme')
        ->assertCookieMissing('appearance');

    expect($user->refresh()->theme_preference)->toBe('light');
})->with(['azul', '', null, 'DARK']);

test('los clientes también pueden cambiar su tema', function () {
    $client = userWithRole('client');

    $this->actingAs($client)->patch(route('appearance.update'), ['theme' => 'dark'])->assertSessionHasNoErrors();

    expect($client->refresh()->theme_preference)->toBe('dark');
});

test('sin cookie, la vista inicial usa la preferencia guardada del usuario', function () {
    $user = userWithRole('employee', ['theme_preference' => 'dark']);

    $this->actingAs($user)->get('/')->assertSee('<html lang="es" class="dark">', false);
});

test('la cookie de tema manda sobre la preferencia guardada y se ignoran valores extraños', function () {
    $user = userWithRole('employee', ['theme_preference' => 'dark']);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'light')
        ->get('/')
        ->assertDontSee('class="dark"', false);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', "dark'</script><script>alert(1)</script>")
        ->get('/')
        ->assertDontSee('alert(1)', false)
        ->assertSee('class="dark"', false);
});
