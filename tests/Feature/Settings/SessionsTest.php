<?php

use App\Auth\SessionTerminator;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
| Estas pruebas usan el driver de sesión "database" (el de producción) y envían la cookie de sesión
| para que la petición use una sesión conocida de la tabla sessions.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
});

test('lista solo las sesiones propias, marca la actual y no expone el id real', function () {
    $user = userWithRole('employee');
    $other = userWithRole('employee');

    $current = insertSession($user, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15');
    $mine = insertSession($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0', now()->subHour()->getTimestamp());
    $foreign = insertSession($other);

    $response = $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/sessions', false)
            ->where('supported', true)
            ->has('sessions', 2)
            ->where('sessions.0.is_current', true)
            ->where('sessions.0.browser', 'Safari')
            ->where('sessions.0.platform', 'macOS')
            ->where('sessions.1.is_current', false)
            ->where('sessions.1.browser', 'Firefox')
            ->where('sessions.1.platform', 'Windows')
            ->has('sessions.0', fn (Assert $session) => $session
                ->hasAll(['id', 'ip_address', 'user_agent', 'browser', 'platform', 'is_current', 'last_active_at'])));

    $content = $response->getContent();

    expect($content)->not->toContain($current)
        ->and($content)->not->toContain($mine)
        ->and($content)->not->toContain($foreign);
});

test('se puede cerrar otra sesión propia', function () {
    $user = userWithRole('employee');
    $current = insertSession($user);
    $mine = insertSession($user);

    $publicId = sessionPublicId($user, $current, $mine);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy', $publicId))
        ->assertRedirect(route('sessions.index'))
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', $mine)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
});

test('no se puede cerrar la sesión actual', function () {
    $user = userWithRole('employee');
    $current = insertSession($user);

    $publicId = sessionPublicId($user, $current, $current);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy', $publicId))
        ->assertSessionHasErrors(['session' => __('app.sessions.cannot_close_current')]);

    expect(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
});

test('no se puede cerrar la sesión de otro usuario', function () {
    $user = userWithRole('employee');
    $other = userWithRole('employee');
    $current = insertSession($user);
    $foreign = insertSession($other);

    // Id público de la sesión ajena, calculado como lo haría el controlador para su dueño.
    $foreignPublicId = sessionPublicId($other, insertSession($other), $foreign);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->delete(route('sessions.destroy', $foreignPublicId))
        ->assertNotFound();

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->delete(route('sessions.destroy', $foreign))
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue();
});

test('cerrar las demás sesiones exige confirmar la contraseña', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('password.confirm'));
});

test('cerrar las demás sesiones deja solo la actual y cambia el token de "recordarme"', function () {
    $user = userWithRole('employee');
    $other = userWithRole('employee');
    $current = insertSession($user);
    insertSession($user);
    insertSession($user);
    $foreign = insertSession($other);
    $oldRememberToken = $user->remember_token;

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('sessions.index'));

    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current])
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and($user->refresh()->remember_token)->not->toBe($oldRememberToken);
});

test('con un driver de sesión distinto de database la lista sale vacía y lo indica', function () {
    config(['session.driver' => 'array']);

    $this->actingAs(userWithRole('employee'))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('supported', false)
            ->has('sessions', 0));
});

/**
 * Id público de $target tal como lo muestra la lista de sesiones de $user (sesión actual $current).
 */
function sessionPublicId(User $user, string $current, string $target): string
{
    $test = test();

    // Se lee antes de la petición: al guardar la sesión actual, Laravel reescribe su IP.
    $ip = DB::table('sessions')->where('id', $target)->value('ip_address');

    $response = $test->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'));

    /** @var list<array{id: string, is_current: bool, ip_address: string}> $sessions */
    $sessions = $response->viewData('page')['props']['sessions'];
    $isCurrent = $target === $current;

    foreach ($sessions as $session) {
        if ($session['is_current'] === $isCurrent && $session['ip_address'] === $ip) {
            return $session['id'];
        }
    }

    throw new RuntimeException('Sesión no encontrada en la lista.');
}

/**
 * Valor de la cookie de «Recordarme» tal como la crea el guard (id|token|HMAC del hash).
 */
function recallerValue(User $user): string
{
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    return $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->password);
}

/**
 * Olvida el usuario autenticado, la sesión y las cookies de las peticiones anteriores, como un
 * navegador distinto (en los tests, el almacén de sesión conserva sus datos entre peticiones).
 */
function freshDevice(TestCase $test): void
{
    Auth::forgetGuards();
    app('session.store')->flush();
    (fn () => $this->defaultCookies = [])->call($test);
}

test('la cookie de «Recordarme» de una sesión cerrada ya no vuelve a abrir sesión', function () {
    $user = userWithRole('employee', ['remember_token' => Str::random(60)]);
    $recallerName = Auth::guard('web')->getRecallerName();
    $stolenRecaller = recallerValue($user);

    // El otro dispositivo entra con su cookie de «Recordarme».
    $this->withCookie($recallerName, $stolenRecaller)->get(route('home'))->assertOk();
    $this->assertAuthenticatedAs($user);

    freshDevice($this);
    $current = insertSession($user);
    $stolen = insertSession($user);
    $publicId = sessionPublicId($user, $current, $stolen);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy', $publicId))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('sessions')->where('id', $stolen)->exists())->toBeFalse();

    freshDevice($this);
    $this->withCookie($recallerName, $stolenRecaller)->get(route('home'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('al cerrar las demás sesiones, este dispositivo recibe la cookie de «Recordarme» con el formato del guard', function () {
    $user = userWithRole('employee', ['remember_token' => Str::random(60)]);
    $recallerName = Auth::guard('web')->getRecallerName();
    $current = insertSession($user);

    $response = $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withCookie($recallerName, recallerValue($user))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('sessions.index'));

    $user->refresh();
    $cookie = $response->getCookie($recallerName, decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and(CookieValuePrefix::remove(Crypt::decryptString((string) $cookie->getValue())))->toBe(recallerValue($user))
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addMinutes(SessionTerminator::REMEMBER_COOKIE_MINUTES - 5)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThan(now()->addMinutes(SessionTerminator::REMEMBER_COOKIE_MINUTES + 5)->getTimestamp());

    // La cookie nueva sigue abriendo sesión en este dispositivo.
    freshDevice($this);
    $this->withCookie($recallerName, recallerValue($user))->get(route('home'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('sin el driver database, cerrar sesiones da un error y no un falso éxito', function () {
    config(['session.driver' => 'array']);
    $user = userWithRole('employee');
    $oldRememberToken = $user->remember_token;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('sessions.index'))
        ->assertSessionHasErrors(['session' => __('app.sessions.unsupported')])
        ->assertInertiaFlash('toast.type', 'error');

    $this->actingAs($user)
        ->from(route('sessions.index'))
        ->delete(route('sessions.destroy', str_repeat('a', 40)))
        ->assertRedirect(route('sessions.index'))
        ->assertSessionHasErrors(['session' => __('app.sessions.unsupported')])
        ->assertInertiaFlash('toast.type', 'error');

    expect($user->refresh()->remember_token)->toBe($oldRememberToken);
});

test('una sesión abierta con la contraseña anterior deja de valer (auth.session)', function () {
    $user = userWithRole('employee');

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $guard->hashPasswordForCookie(Hash::make('contraseña-anterior'))])
        ->get(route('home'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
