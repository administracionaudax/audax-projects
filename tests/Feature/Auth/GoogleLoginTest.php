<?php

use App\Domain\Audit\AuditCatalog;
use App\Domain\Auth\Google\GoogleLogin;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\InvitationController;
use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Integrations\GoogleFakes;

/*
| Entrar con Google (D-165): OpenID Connect con el cliente de Google Sheets (D-142), state, nonce
| y PKCE, validación del id_token en el servidor (dominio permitido, `hd` y correo verificado),
| solo personas que ya existen (activas, de la plantilla y sin ser colaboradores externos), el 2FA
| propio si lo tienen, el registro de accesos, la auditoría, el límite y el interruptor de
| /admin/ajustes. Nunca se llama a Google: Http::fake y preventStrayRequests.
*/

const GOOGLE_LOGIN_CALLBACK = 'https://projects.audaxstudio.com/login/google/callback';

beforeEach(function () {
    GoogleFakes::configure();
    config(['services.google.login_redirect' => GOOGLE_LOGIN_CALLBACK, 'services.google.login_domains' => 'audaxstudio.com']);
    Http::preventStrayRequests();

    $this->user = userWithRole('employee', ['email' => 'elena@audaxstudio.com']);
});

/**
 * Sesión con un acceso con Google en curso.
 *
 * @return array<string, array<string, mixed>>
 */
function googleLoginSession(array $overrides = []): array
{
    return [GoogleLoginController::SESSION_KEY => [
        'state' => 'estado-valido',
        'verifier' => 'verificador-pkce',
        'nonce' => 'nonce-valido',
        'remember' => false,
        'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ...$overrides,
    ]];
}

/** Respuesta del endpoint de tokens con un id_token de esas claims. */
function fakeGoogleLoginToken(array $claims = [], int $status = 200): void
{
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response([
        'access_token' => 'ya29.acceso',
        'expires_in' => 3599,
        'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        'token_type' => 'Bearer',
        'id_token' => GoogleFakes::idToken(['nonce' => 'nonce-valido', ...$claims]),
    ], $status)]);
}

function googleCallback(array $query = []): string
{
    return route('login.google.callback', ['state' => 'estado-valido', 'code' => 'codigo-de-google', ...$query]);
}

describe('disponibilidad', function () {
    test('con credenciales, /login ofrece «Entrar con Google»', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('googleLogin', true));
    });

    test('sin credenciales, no se ofrece y las rutas responden 404', function () {
        config(['services.google.client_id' => '', 'services.google.client_secret' => '']);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleLogin', false));
        $this->post(route('login.google'))->assertNotFound();
        $this->get(googleCallback())->assertNotFound();

        Http::assertNothingSent();
    });

    test('con el ajuste desactivado, no se ofrece y las rutas responden 404', function () {
        Setting::set('google_login_enabled', false);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleLogin', false));
        $this->post(route('login.google'))->assertNotFound();
        $this->withSession(googleLoginSession())->get(googleCallback())->assertNotFound();

        Http::assertNothingSent();
        $this->assertGuest();
    });

    test('con sesión iniciada, las rutas llevan a Inicio', function () {
        $this->actingAs($this->user)->post(route('login.google'))->assertRedirect(route('dashboard'));
    });

    test('la invitación ofrece Google solo a los correos de un dominio permitido', function () {
        $this->get(route('invitation.show', ['token' => 'abc', 'email' => 'Elena@AudaxStudio.com']))
            ->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation')->where('googleLogin', true));

        $this->get(route('invitation.show', ['token' => 'abc', 'email' => 'amparo@gmail.com']))
            ->assertInertia(fn (Assert $page) => $page->where('googleLogin', false));
    });
});

describe('salida hacia Google', function () {
    test('guarda state, PKCE y nonce en la sesión y manda a Google con los alcances mínimos y hd', function () {
        $response = $this->post(route('login.google'), ['remember' => true], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $location = (string) $response->headers->get('X-Inertia-Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $pending = session(GoogleLoginController::SESSION_KEY);

        expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
            ->and($query['client_id'])->toBe(GoogleFakes::CLIENT_ID)
            ->and($query['redirect_uri'])->toBe(GOOGLE_LOGIN_CALLBACK)
            ->and($query['response_type'])->toBe('code')
            ->and($query['scope'])->toBe('openid email profile')
            ->and($query['hd'])->toBe('audaxstudio.com')
            ->and($query['prompt'])->toBe('select_account')
            ->and($query['code_challenge_method'])->toBe('S256')
            ->and($query)->not->toHaveKey('access_type')
            ->and($query['state'])->toBe($pending['state'])
            ->and($query['nonce'])->toBe($pending['nonce'])
            ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='))
            ->and($pending['remember'])->toBeTrue()
            ->and(strlen($pending['state']))->toBe(40);

        Http::assertNothingSent();
    });

    test('sin URI configurada usa la ruta del callback; con varios dominios no manda hd', function () {
        config(['services.google.login_redirect' => null, 'services.google.login_domains' => 'audaxstudio.com, @Otra-Agencia.es']);

        $location = (string) $this->post(route('login.google'), [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect($query['redirect_uri'])->toBe(route('login.google.callback'))
            ->and($query)->not->toHaveKey('hd')
            ->and(GoogleLogin::allowedDomains())->toBe(['audaxstudio.com', 'otra-agencia.es']);
    });
});

describe('vuelta de Google', function () {
    test('con una cuenta válida, entra, regenera la sesión y lo registra como google', function () {
        fakeGoogleLoginToken();
        $this->withSession(googleLoginSession());
        $before = session()->getId();

        $this->get(googleCallback())->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
        expect(session()->getId())->not->toBe($before)
            ->and(session()->has(GoogleLoginController::SESSION_KEY))->toBeFalse()
            ->and(session()->has(GoogleLoginController::GOOGLE_USER))->toBeFalse();

        $event = LoginEvent::query()->sole();
        expect($event->user_id)->toBe($this->user->id)
            ->and($event->succeeded)->toBeTrue()
            ->and($event->method)->toBe('google');

        $activity = Activity::query()->where('log_name', 'auth')->sole();
        expect($activity->event)->toBe('google_login')
            ->and($activity->subject_id)->toBe($this->user->id)
            ->and($activity->causer_id)->toBe($this->user->id)
            ->and($activity->getProperty('google_email'))->toBe('elena@audaxstudio.com')
            ->and($activity->getProperty('two_factor_pending'))->toBeFalse();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === GoogleOAuth::TOKEN_URL
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'codigo-de-google'
            && $request['code_verifier'] === 'verificador-pkce'
            && $request['redirect_uri'] === GOOGLE_LOGIN_CALLBACK
            && $request['client_id'] === GoogleFakes::CLIENT_ID);
    });

    test('el correo se compara en minúsculas y vuelve a la página que se pedía', function () {
        $this->user->forceFill(['email' => 'Elena@AudaxStudio.com'])->save();
        fakeGoogleLoginToken(['email' => 'ELENA@audaxstudio.com']);

        $this->withSession([...googleLoginSession(), 'url.intended' => url('/tareas')])
            ->get(googleCallback())
            ->assertRedirect(url('/tareas'));

        $this->assertAuthenticatedAs($this->user);
    });

    test('con «Mantener la sesión iniciada», deja la cookie de recordar', function () {
        fakeGoogleLoginToken();

        $response = $this->withSession(googleLoginSession(['remember' => true]))->get(googleCallback());

        $this->assertAuthenticatedAs($this->user);
        expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')))->toBeTrue();
    });

    test('acepta la invitación pendiente y verifica el correo', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();
        InvitationController::urlFor($this->user);
        expect(DB::table('invitation_tokens')->count())->toBe(1);

        fakeGoogleLoginToken();
        $this->withSession(googleLoginSession())->get(googleCallback())->assertRedirect('/');

        expect(DB::table('invitation_tokens')->count())->toBe(0)
            ->and($this->user->fresh()?->email_verified_at)->not->toBeNull();
    });

    test('rechaza un state que no es el de la sesión, sin llamar a Google', function (array $session, array $query) {
        $this->withSession($session)
            ->get(googleCallback($query))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['google' => 'El acceso con Google ha caducado o no es válido. Vuelve a intentarlo.']);

        $this->assertGuest();
        Http::assertNothingSent();
        expect(LoginEvent::query()->count())->toBe(0)
            ->and(session()->has(GoogleLoginController::SESSION_KEY))->toBeFalse();
    })->with([
        'otro state' => [fn () => googleLoginSession(), ['state' => 'estado-falso']],
        'sin sesión' => [fn () => [], []],
        'caducado' => [fn () => googleLoginSession(['expires_at' => now()->subSecond()->getTimestamp()]), []],
        'sin nonce' => [fn () => googleLoginSession(['nonce' => null]), []],
    ]);

    test('el state es de un solo uso', function () {
        fakeGoogleLoginToken();
        $this->withSession(googleLoginSession())->get(googleCallback());
        auth()->logout();

        $this->get(googleCallback())->assertSessionHasErrors('google');
        Http::assertSentCount(1);
    });

    test('si la persona cancela en Google, no entra', function () {
        $this->withSession(googleLoginSession())
            ->get(googleCallback(['error' => 'access_denied', 'code' => null]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['google' => 'Has cancelado el acceso con Google.']);

        $this->assertGuest();
        Http::assertNothingSent();
    });

    test('rechaza un id_token que no vale', function (array $claims) {
        fakeGoogleLoginToken($claims);

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => 'No hemos podido comprobar tu cuenta de Google. Vuelve a intentarlo.']);

        $this->assertGuest();
    })->with([
        'otro nonce' => [['nonce' => 'nonce-de-otro']],
        'otra audiencia' => [['aud' => 'otro-cliente.apps.googleusercontent.com']],
        'otro emisor' => [['iss' => 'https://evil.example.com']],
        'caducado' => [fn () => ['exp' => now()->subMinute()->getTimestamp()]],
    ]);

    test('rechaza una cuenta con el correo sin verificar', function () {
        fakeGoogleLoginToken(['email_verified' => false]);

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => 'Tu cuenta de Google no tiene el correo verificado.']);

        $this->assertGuest();
        expect(LoginEvent::query()->sole())->method->toBe('google')->succeeded->toBeFalse();
    });

    test('rechaza cuentas de un dominio no permitido o que no son de Workspace', function (array $claims) {
        userWithRole('employee', ['email' => 'elena@gmail.com']);
        fakeGoogleLoginToken($claims);

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => 'Solo se puede entrar con una cuenta de Google de Audax Studio (@audaxstudio.com).']);

        $this->assertGuest();

        $activity = Activity::query()->where('log_name', 'auth')->sole();
        expect($activity->event)->toBe('google_login_rejected')
            ->and($activity->getProperty('reason'))->toBe('wrong_domain');
    })->with([
        'Gmail' => [['email' => 'elena@gmail.com', 'hd' => null]],
        'cuenta personal con el correo de la empresa (sin hd)' => [['hd' => null]],
        'hd de otro dominio' => [['hd' => 'otra-agencia.es']],
        'subdominio' => [['email' => 'elena@mail.audaxstudio.com', 'hd' => 'mail.audaxstudio.com']],
    ]);

    test('acepta cualquiera de los dominios configurados', function () {
        config(['services.google.login_domains' => 'audaxstudio.com,otra-agencia.es']);
        $other = userWithRole('employee', ['email' => 'luis@otra-agencia.es']);
        fakeGoogleLoginToken(['email' => 'luis@otra-agencia.es', 'hd' => 'otra-agencia.es']);

        $this->withSession(googleLoginSession())->get(googleCallback())->assertRedirect('/');

        $this->assertAuthenticatedAs($other);
    });

    test('nunca crea usuarios: sin cuenta en la app, no entra', function () {
        fakeGoogleLoginToken(['email' => 'nueva@audaxstudio.com']);
        $users = User::query()->count();

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['google' => 'No hay ninguna cuenta en Audax Proyectos con nueva@audaxstudio.com. Pide a la administración que te dé de alta.']);

        $this->assertGuest();
        expect(User::query()->count())->toBe($users);

        $event = LoginEvent::query()->sole();
        expect($event->user_id)->toBeNull()
            ->and($event->email)->toBe('nueva@audaxstudio.com')
            ->and($event->method)->toBe('google')
            ->and($event->succeeded)->toBeFalse();
    });

    test('una cuenta desactivada no entra', function () {
        $this->user->forceFill(['is_active' => false])->save();
        fakeGoogleLoginToken();

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => __('app.account_inactive')]);

        $this->assertGuest();
        expect(LoginEvent::query()->sole())->user_id->toBe($this->user->id)->succeeded->toBeFalse();
        expect(Activity::query()->where('log_name', 'auth')->sole())->subject_id->toBe($this->user->id);
    });

    test('ni los colaboradores externos ni los clientes entran con Google', function (string $role) {
        $person = userWithRole($role, ['email' => 'externa@audaxstudio.com']);
        fakeGoogleLoginToken(['email' => 'externa@audaxstudio.com']);

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => 'El acceso con Google es solo para la plantilla. Entra con tu correo y tu contraseña.']);

        $this->assertGuest();
        expect(Activity::query()->where('log_name', 'auth')->sole()->getProperty('reason'))->toBe($role);
        expect(LoginEvent::query()->sole()->user_id)->toBe($person->id);
    })->with(['collaborator', 'client']);

    test('si Google no responde o rechaza el código, no entra', function (int $status, string $message) {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], $status)]);

        $this->withSession(googleLoginSession())
            ->get(googleCallback())
            ->assertSessionHasErrors(['google' => $message]);

        $this->assertGuest();
    })->with([
        'caído' => [503, 'Google no responde ahora mismo. Inténtalo de nuevo en unos minutos.'],
        'código no válido' => [400, 'Google no ha aceptado el acceso. Vuelve a intentarlo.'],
    ]);
});

describe('verificación en dos pasos', function () {
    test('quien tiene el 2FA propio activado tiene que pasarlo también con Google', function () {
        $user = User::factory()->withTwoFactor()->employee()->create(['email' => 'tomas@audaxstudio.com']);
        fakeGoogleLoginToken(['email' => 'tomas@audaxstudio.com']);

        $this->withSession(googleLoginSession(['remember' => true]))
            ->get(googleCallback())
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        expect(session('login.id'))->toBe($user->id)
            ->and(session('login.remember'))->toBeTrue()
            ->and(LoginEvent::query()->count())->toBe(0)
            ->and(Activity::query()->where('log_name', 'auth')->sole()->getProperty('two_factor_pending'))->toBeTrue();

        $this->get(route('two-factor.login'))->assertOk();

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect(LoginEvent::query()->sole())->method->toBe('google')->succeeded->toBeTrue();
    });

    test('un código incorrecto tras Google queda como acceso fallido con Google', function () {
        User::factory()->withTwoFactor()->employee()->create(['email' => 'tomas@audaxstudio.com']);
        fakeGoogleLoginToken(['email' => 'tomas@audaxstudio.com']);

        $this->withSession(googleLoginSession())->get(googleCallback());
        $this->post(route('two-factor.login.store'), ['code' => '000000']);

        $this->assertGuest();
        expect(LoginEvent::query()->sole())->method->toBe('google')->succeeded->toBeFalse();
    });

    test('un 2FA con contraseña después de un Google a medias se registra como contraseña', function () {
        $user = User::factory()->withTwoFactor()->employee()->create(['email' => 'tomas@audaxstudio.com']);
        fakeGoogleLoginToken(['email' => 'tomas@audaxstudio.com']);
        $this->withSession(googleLoginSession())->get(googleCallback());

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

        $this->assertAuthenticatedAs($user);
        expect(LoginEvent::query()->sole())->method->toBe('password');
    });

    test('con el 2FA obligatorio, quien no lo tiene entra y se le lleva a configurarlo', function () {
        Setting::set('require_2fa', true);
        fakeGoogleLoginToken();

        $this->withSession(googleLoginSession())->get(googleCallback())->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);

        $this->get('/')->assertRedirect(route('security.edit'));
    });
});

test('el login con contraseña sigue registrándose como password', function () {
    $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($this->user);
    expect(LoginEvent::query()->sole()->method)->toBe('password');
});

test('el límite de intentos corta la salida y la vuelta de Google', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->get(googleCallback(['state' => 'x']));
    }

    $this->get(googleCallback(['state' => 'x']))->assertTooManyRequests();
    $this->post(route('login.google'))->assertTooManyRequests();

    RateLimiter::clear('google-login:127.0.0.1');
});

test('la auditoría tiene la entidad «Accesos con Google» y sus motivos', function () {
    expect(AuditCatalog::entityOf('auth'))->toBe('access')
        ->and(AuditCatalog::entityLabel('access'))->toBe('Accesos con Google')
        ->and(AuditCatalog::ACTIONS['google_login'])->toBe(['google_login', 'google_login_rejected'])
        ->and(AuditCatalog::eventLabel('google_login_rejected'))->toBe('Entrada con Google rechazada');

    fakeGoogleLoginToken(['email' => 'nadie@audaxstudio.com']);
    $this->withSession(googleLoginSession())->get(googleCallback());

    $admin = userWithRole('admin');
    $this->actingAs($admin)
        ->get(route('admin.audit.index', ['entidad' => 'access']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.event_label', 'Entrada con Google rechazada')
            ->where('entries.0.changes', fn ($changes) => collect($changes)->contains(fn ($change) => $change['to'] === 'No tiene cuenta en la app')));
});

describe('ajuste en /admin/ajustes', function () {
    test('está activado por defecto y la página dice si hay credenciales', function () {
        $this->actingAs(userWithRole('admin'))
            ->get(route('admin.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.google_login_enabled', true)
                ->where('googleLogin.configured', true)
                ->where('googleLogin.domains', ['audaxstudio.com']));
    });

    test('el admin lo desactiva y queda en la auditoría', function () {
        $admin = userWithRole('admin');
        $this->actingAs($admin)->get(route('admin.settings.edit'));
        $payload = [
            ...collect(Setting::DEFAULTS)->only([
                'company_name', 'require_2fa', 'timer_rounding_minutes', 'timer_warning_hours', 'hour_bank_alert_thresholds',
                'allow_hour_bank_overage', 'require_timesheet_approval', 'allow_future_time_entries', 'time_entry_description_required',
                'max_attachment_mb', 'default_work_minutes', 'weekly_digest_enabled', 'occupancy_low_threshold', 'occupancy_high_threshold',
            ])->all(),
            'google_login_enabled' => false,
        ];

        $this->actingAs($admin)->put(route('admin.settings.update'), $payload)->assertSessionHasNoErrors();

        expect(Setting::get('google_login_enabled'))->toBeFalse()
            ->and(GoogleLogin::available())->toBeFalse()
            ->and(Activity::query()->where('log_name', 'settings')->latest('id')->first()?->getProperty('attributes'))->toBe(['google_login_enabled' => false]);
    });

    test('quien no lo envía conserva el valor guardado', function () {
        Setting::set('google_login_enabled', false);
        $admin = userWithRole('admin');
        $payload = collect(Setting::DEFAULTS)->only([
            'company_name', 'require_2fa', 'timer_rounding_minutes', 'timer_warning_hours', 'hour_bank_alert_thresholds',
            'allow_hour_bank_overage', 'require_timesheet_approval', 'allow_future_time_entries', 'time_entry_description_required',
            'max_attachment_mb', 'default_work_minutes', 'weekly_digest_enabled', 'occupancy_low_threshold', 'occupancy_high_threshold',
        ])->all();

        $this->actingAs($admin)->put(route('admin.settings.update'), $payload)->assertSessionHasNoErrors();

        expect(Setting::get('google_login_enabled'))->toBeFalse();
    });
});
