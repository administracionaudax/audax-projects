<?php

use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleReconnectRequired;
use App\Domain\Integrations\Google\GoogleUnavailable;
use App\Domain\Privacy\Export\Sections\IntegrationsSection;
use App\Http\Controllers\Integrations\IntegrationsController;
use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Integrations\GoogleFakes;

/*
| Ajustes → Integraciones (Fase 9, D-142): conexión de la cuenta de Google de cada persona con
| OAuth 2.0 (state anti-CSRF, PKCE, offline, hd del dominio y alcance drive.file), tokens cifrados,
| renovación, invalid_grant, desconexión con revocación y la opción oculta sin credenciales.
| Nunca se llama a Google: Http::fake y preventStrayRequests.
*/

beforeEach(function () {
    GoogleFakes::configure();
    Http::preventStrayRequests();

    $this->user = userWithRole('employee');
});

describe('sin credenciales', function () {
    beforeEach(function () {
        config(['services.google.client_id' => '', 'services.google.client_secret' => null]);
    });

    test('la página dice que no está disponible y la opción no se ofrece', function () {
        $this->actingAs($this->user)
            ->get(route('integrations.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/integrations')
                ->where('google.available', false)
                ->where('google.connection', null)
                ->where('integrations.google_sheets', false)
                ->where('integrations.google_connected', false));
    });

    test('con solo el ID o solo el secreto tampoco se ofrece', function (array $config) {
        config($config);

        expect(GoogleOAuth::configured())->toBeFalse();
    })->with([
        'solo el ID' => [['services.google.client_id' => 'id', 'services.google.client_secret' => '']],
        'solo el secreto' => [['services.google.client_id' => '  ', 'services.google.client_secret' => 'secreto']],
    ]);

    test('conectar, volver de Google y exportar responden 404', function () {
        $this->actingAs($this->user)->post(route('integrations.google.connect'))->assertNotFound();
        $this->actingAs($this->user)->get(route('integrations.google.callback', ['state' => 'x', 'code' => 'y']))->assertNotFound();
        $this->actingAs($this->user)->postJson(route('reports.sheets.store'), ['kind' => 'direction'])->assertNotFound();

        Http::assertNothingSent();
    });
});

test('con credenciales, la página ofrece conectar y la prop compartida activa Google Sheets', function () {
    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/integrations')
            ->where('google.available', true)
            ->where('google.connection', null)
            ->where('integrations.google_sheets', true)
            ->where('integrations.google_connected', false));
});

test('con la cuenta conectada, la página la muestra sin tokens', function () {
    GoogleFakes::connect($this->user);

    $response = $this->actingAs($this->user)->get(route('integrations.edit'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('google.connection.email', 'elena@audaxstudio.com')
            ->has('google.connection.connected_at')
            ->where('integrations.google_connected', true));

    expect($response->getContent())->not->toContain('refresco-guardado')
        ->and($response->getContent())->not->toContain('acceso-guardado');
});

test('conectar manda a Google con state, PKCE, offline, consentimiento, dominio y alcance mínimo', function () {
    $response = $this->actingAs($this->user)->post(route('integrations.google.connect'));

    $response->assertRedirect();
    $url = (string) $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $pending = session(IntegrationsController::SESSION_KEY);

    expect($url)->toStartWith(GoogleOAuth::AUTHORIZE_URL.'?')
        ->and($query)->toMatchArray([
            'client_id' => GoogleFakes::CLIENT_ID,
            'redirect_uri' => 'https://projects.audaxstudio.com/integraciones/google/callback',
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/drive.file',
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'hd' => 'audaxstudio.com',
        ])
        ->and($pending['state'])->toBe($query['state'])
        ->and(strlen($pending['state']))->toBe(40)
        // S256: el reto es el SHA-256 del verificador en base64url.
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='));

    Http::assertNothingSent();
});

test('desde Inertia, conectar responde con la URL de Google para abrirla fuera de la SPA', function () {
    $this->actingAs($this->user)
        ->post(route('integrations.google.connect'), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

test('al reconectar con una conexión existente basta con elegir la cuenta', function () {
    GoogleFakes::connect($this->user);

    $url = (string) $this->actingAs($this->user)->post(route('integrations.google.connect'))->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['prompt'])->toBe('select_account');
});

test('la URI de redirección por defecto es la ruta del callback', function () {
    config(['services.google.redirect' => null]);

    expect(app(GoogleOAuth::class)->redirectUri())->toBe(route('integrations.google.callback'))
        ->and(route('integrations.google.callback', absolute: false))->toBe('/integraciones/google/callback');
});

test('un state inválido, ausente o caducado no conecta nada ni llama a Google', function (array $session, ?string $state) {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession($session)
        ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'codigo']))
        ->assertRedirect(route('integrations.edit'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('integrations.google.errors.invalid_state'));

    expect(GoogleConnection::query()->count())->toBe(0)
        ->and(session()->has(IntegrationsController::SESSION_KEY))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'otro state' => [fn () => GoogleFakes::pendingSession('estado-valido'), 'estado-de-otro'],
    'sin state' => [fn () => GoogleFakes::pendingSession('estado-valido'), null],
    'sin flujo en la sesión' => [fn () => [], 'estado-valido'],
    'caducado' => [fn () => GoogleFakes::pendingSession('estado-valido', now()->subSecond()->getTimestamp()), 'estado-valido'],
]);

test('el state es de un solo uso: repetir la vuelta no conecta', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse())]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.type', 'success');

    GoogleConnection::query()->delete();

    $this->actingAs($this->user)
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.message', __('integrations.google.errors.invalid_state'));

    expect(GoogleConnection::query()->count())->toBe(0);
});

test('si la persona cancela en Google, se avisa sin llamar a Google', function () {
    Http::fake();

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'error' => 'access_denied']))
        ->assertRedirect(route('integrations.edit'))
        ->assertInertiaFlash('toast.message', __('integrations.google.errors.denied'));

    Http::assertNothingSent();
});

test('éxito: cambia el código con PKCE y guarda la conexión con los tokens cifrados', function () {
    $this->freezeSecond();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse())]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => '4/codigo-de-google']))
        ->assertRedirect(route('integrations.edit'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Cuenta de Google conectada: elena@audaxstudio.com.');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::TOKEN_URL
        && $request->method() === 'POST'
        && $request->isForm()
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === '4/codigo-de-google'
        && $request['code_verifier'] === 'verificador-pkce'
        && $request['redirect_uri'] === 'https://projects.audaxstudio.com/integraciones/google/callback'
        && $request['client_id'] === GoogleFakes::CLIENT_ID
        && $request['client_secret'] === 'secreto-falso');

    $connection = $this->user->refresh()->googleConnection;

    expect($connection)->not->toBeNull()
        ->and($connection->google_email)->toBe('elena@audaxstudio.com')
        ->and($connection->refresh_token)->toBe('1//refresco-nuevo')
        ->and($connection->access_token)->toBe('ya29.acceso-nuevo')
        ->and($connection->expires_at?->getTimestamp())->toBe(now()->addSeconds(3599)->getTimestamp())
        ->and($connection->scopes)->toContain(GoogleOAuth::DRIVE_FILE_SCOPE)
        ->and($connection->toArray())->not->toHaveKeys(['refresh_token', 'access_token']);

    // En crudo, en la base, los tokens nunca están en claro.
    $raw = (array) DB::table('google_connections')->where('user_id', $this->user->id)->first();

    expect($raw['refresh_token'])->not->toContain('refresco-nuevo')
        ->and($raw['access_token'])->not->toContain('acceso-nuevo')
        ->and(decrypt($raw['refresh_token'], false))->toBe('1//refresco-nuevo')
        ->and(json_encode($raw))->not->toContain('refresco-nuevo');
});

test('una cuenta de otro dominio no se conecta y se revoca lo recibido', function (array $claims) {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse(['id_token' => GoogleFakes::idToken($claims)])),
        'oauth2.googleapis.com/revoke' => Http::response('', 200),
    ]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Solo puedes conectar una cuenta de Google de Audax Studio (@audaxstudio.com).');

    expect(GoogleConnection::query()->count())->toBe(0);

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::REVOKE_URL && $request['token'] === '1//refresco-nuevo');
})->with([
    'gmail' => [['email' => 'elena@gmail.com', 'hd' => null]],
    'otro Workspace' => [['email' => 'elena@otra.com', 'hd' => 'otra.com']],
    'hd sin correo del dominio' => [['email' => 'elena@otra.com', 'hd' => 'audaxstudio.com']],
    'correo sin verificar' => [['email_verified' => false]],
    'token para otra app' => [['aud' => 'otra-app.apps.googleusercontent.com']],
    'emisor falso' => [['iss' => 'https://evil.example.com']],
    'caducado' => [['exp' => 1000]],
]);

test('sin el permiso de Drive (consentimiento parcial) no se conecta', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse(['scope' => 'openid https://www.googleapis.com/auth/userinfo.email'])),
        'oauth2.googleapis.com/revoke' => Http::response('', 200),
    ]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.message', __('integrations.google.errors.missing_scope'));

    expect(GoogleConnection::query()->count())->toBe(0);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::REVOKE_URL);
});

test('sin token de refresco y sin conexión previa no se conecta', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse(['refresh_token' => null]))]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.message', __('integrations.google.errors.missing_refresh_token'));

    expect(GoogleConnection::query()->count())->toBe(0);
});

test('al reconectar la misma cuenta sin token de refresco nuevo se conserva el guardado', function () {
    GoogleFakes::connect($this->user);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(GoogleFakes::tokenResponse(['refresh_token' => null]))]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.type', 'success');

    $connection = $this->user->refresh()->googleConnection;

    expect(GoogleConnection::query()->count())->toBe(1)
        ->and($connection?->refresh_token)->toBe('1//refresco-guardado')
        ->and($connection?->access_token)->toBe('ya29.acceso-nuevo');
});

test('si Google rechaza el código o no responde, se avisa sin conectar', function (int $status, string $message) {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], $status)]);

    $this->actingAs($this->user)->withSession(GoogleFakes::pendingSession())
        ->get(route('integrations.google.callback', ['state' => 'estado-valido', 'code' => 'codigo']))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __($message));

    expect(GoogleConnection::query()->count())->toBe(0);
})->with([
    'código rechazado' => [400, 'integrations.google.errors.exchange_failed'],
    'Google caído' => [503, 'integrations.google.errors.unavailable'],
]);

test('renueva el token de acceso caducado con el de refresco', function () {
    $this->freezeSecond();
    GoogleFakes::connect($this->user, ['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.renovado', 'expires_in' => 3599, 'scope' => GoogleOAuth::DRIVE_FILE_SCOPE, 'token_type' => 'Bearer'])]);

    $token = app(GoogleOAuth::class)->accessToken($this->user);

    expect($token)->toBe('ya29.renovado');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === '1//refresco-guardado'
        && $request['client_secret'] === 'secreto-falso');

    $connection = $this->user->refresh()->googleConnection;

    expect($connection?->access_token)->toBe('ya29.renovado')
        ->and($connection?->refresh_token)->toBe('1//refresco-guardado')
        ->and($connection?->expires_at?->getTimestamp())->toBe(now()->addSeconds(3599)->getTimestamp());
});

test('con el token de acceso vigente no se renueva', function () {
    GoogleFakes::connect($this->user);
    Http::fake();

    expect(app(GoogleOAuth::class)->accessToken($this->user))->toBe('ya29.acceso-guardado');

    Http::assertNothingSent();
});

test('si Google responde invalid_grant al renovar, se borra la conexión y hay que reconectar', function () {
    GoogleFakes::connect($this->user, ['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

    expect(fn () => app(GoogleOAuth::class)->accessToken($this->user))->toThrow(GoogleReconnectRequired::class);
    expect(GoogleConnection::query()->count())->toBe(0);
});

test('si Google no responde al renovar, la conexión se conserva', function () {
    GoogleFakes::connect($this->user, ['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'backend_error'], 503)]);

    expect(fn () => app(GoogleOAuth::class)->accessToken($this->user))->toThrow(GoogleUnavailable::class);
    expect(GoogleConnection::query()->count())->toBe(1);
});

test('desconectar revoca en Google y borra la conexión', function () {
    GoogleFakes::connect($this->user);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

    $this->actingAs($this->user)
        ->delete(route('integrations.google.destroy'))
        ->assertRedirect(route('integrations.edit'))
        ->assertInertiaFlash('toast.message', __('integrations.google.disconnected'));

    Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::REVOKE_URL
        && $request->isForm()
        && $request['token'] === '1//refresco-guardado');

    expect(GoogleConnection::query()->count())->toBe(0);
});

test('si Google no confirma la revocación, la conexión se borra igualmente y se avisa', function () {
    GoogleFakes::connect($this->user);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);

    $this->actingAs($this->user)
        ->delete(route('integrations.google.destroy'))
        ->assertInertiaFlash('toast.message', __('integrations.google.disconnected_unconfirmed'));

    expect(GoogleConnection::query()->count())->toBe(0);
});

test('cada persona solo desconecta su propia cuenta', function () {
    $other = userWithRole('employee');
    GoogleFakes::connect($other);
    Http::fake();

    $this->actingAs($this->user)->delete(route('integrations.google.destroy'))->assertRedirect(route('integrations.edit'));

    expect($other->googleConnection()->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('un colaborador externo no tiene Integraciones ni la prop de Google Sheets', function () {
    $collaborator = User::factory()->collaborator()->create();

    foreach ([
        ['get', route('integrations.edit')],
        ['post', route('integrations.google.connect')],
        ['get', route('integrations.google.callback', ['state' => 'x', 'code' => 'y'])],
        ['delete', route('integrations.google.destroy')],
    ] as [$method, $url]) {
        $this->actingAs($collaborator)->{$method}($url)->assertForbidden();
    }

    $this->actingAs($collaborator)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integrations.google_sheets', false)
            ->where('integrations.google_connected', false));

    Http::assertNothingSent();
});

test('el portal nunca entra en Integraciones', function () {
    $client = userWithRole('client');

    $this->actingAs($client)->get(route('integrations.edit'))->assertRedirect();
    $this->actingAs($client)->post(route('integrations.google.connect'))->assertRedirect();

    Http::assertNothingSent();
});

test('la exportación de datos personales incluye la cuenta conectada, nunca los tokens', function () {
    GoogleFakes::connect($this->user);
    $section = new IntegrationsSection;

    $rows = iterator_to_array($section->rows($this->user), false);

    expect($section->key())->toBe('integraciones')
        ->and(array_keys($section->columns()))->toBe(['service', 'account', 'connected_at'])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['service'])->toBe('Google')
        ->and($rows[0]['account'])->toBe('elena@audaxstudio.com')
        ->and($rows[0]['connected_at'])->not->toBeNull()
        ->and(json_encode($rows))->not->toContain('refresco')
        ->and(iterator_to_array($section->rows(userWithRole('employee')), false))->toBe([]);
});
