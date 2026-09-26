<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Estas pruebas usan el driver de sesión "database" (el de producción) y envían la cookie de sesión
| para que la petición use una sesión conocida de la tabla sessions.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
});

function insertSession(?User $user, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/131.0', ?int $lastActivity = null): string
{
    static $counter = 0;
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user?->id,
        'ip_address' => '10.0.'.intdiv(++$counter, 250).'.'.($counter % 250 + 1),
        'user_agent' => $userAgent,
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);

    return $id;
}

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
