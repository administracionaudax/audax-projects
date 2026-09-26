<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
| Política de contraseñas fuera de local y testing (AppServiceProvider::configureDefaults).
*/

beforeEach(function () {
    app()['env'] = 'staging';
});

test('exige 12 caracteres con mayúsculas, minúsculas, números y símbolos', function (string $password, bool $valid) {
    Http::preventStrayRequests();

    expect(Validator::make(['password' => $password], ['password' => Password::default()])->passes())->toBe($valid);
})->with([
    'válida' => ['Audax-Studio-2026', true],
    'corta' => ['Aa1!aaaa', false],
    'sin mayúsculas' => ['audax-studio-2026', false],
    'sin números' => ['Audax-Studio-xx', false],
    'sin símbolos' => ['AudaxStudio2026', false],
]);

test('no consulta servicios externos al validar (SPEC §15: ningún dato a terceros)', function () {
    Http::preventStrayRequests();

    // Con ->uncompromised() esta validación llamaría a api.pwnedpasswords.com y lanzaría una excepción.
    expect(Validator::make(['password' => 'Audax-Studio-2026'], ['password' => Password::default()])->passes())->toBeTrue();

    Http::assertNothingSent();
});
