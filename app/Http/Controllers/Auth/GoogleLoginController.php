<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Google\GoogleLogin;
use App\Domain\Auth\Google\GoogleLoginAudit;
use App\Domain\Auth\Google\GoogleLoginRejected;
use App\Domain\Auth\Google\GoogleLoginRejection;
use App\Domain\Integrations\Google\GoogleUnavailable;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * «Entrar con Google» (D-165), en /login y en la invitación de alta. Solo invitados.
 *
 * - redirect (POST /login/google): guarda en la sesión el `state`, el verificador PKCE, el `nonce`
 *   y «Mantener la sesión iniciada» (un solo uso, 10 minutos) y manda a Google,
 * - callback (GET /login/google/callback): comprueba el `state`, valida el id_token
 *   (GoogleLogin::identify) y busca a la persona (GoogleLogin::resolveUser). Si tiene el 2FA
 *   propio activado, se le pide como tras la contraseña (el reto de Fortify); si no, entra.
 *
 * Igual que el login con contraseña: sesión regenerada, «Recordarme», registro de accesos
 * (login_events con method = google, correcto y fallido), último acceso, límite de intentos
 * (google-login) y la política de 2FA obligatorio (RequireTwoFactor). Además, auditoría
 * (GoogleLoginAudit). Sin credenciales o con el ajuste desactivado, responde 404.
 */
class GoogleLoginController extends Controller
{
    public const string SESSION_KEY = 'login.google_oauth';

    /** Id de la persona que entra con Google: lo consume RecordSuccessfulLogin para el método. */
    public const string GOOGLE_USER = 'login.google_user';

    private const int STATE_TTL_SECONDS = 600;

    public function redirect(Request $request, GoogleLogin $google): SymfonyResponse
    {
        abort_unless(GoogleLogin::available(), 404);

        $authorization = $google->authorization();

        $request->session()->put(self::SESSION_KEY, [
            'state' => $authorization['state'],
            'verifier' => $authorization['verifier'],
            'nonce' => $authorization['nonce'],
            'remember' => $request->boolean('remember'),
            'expires_at' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
        ]);

        return Inertia::location($authorization['url']);
    }

    public function callback(Request $request, GoogleLogin $google): RedirectResponse
    {
        abort_unless(GoogleLogin::available(), 404);

        // De un solo uso: se saca de la sesión pase lo que pase.
        $pending = $this->pending($request->session()->pull(self::SESSION_KEY), $request->query('state'));

        try {
            if ($pending === null) {
                throw new GoogleLoginRejected(GoogleLoginRejection::InvalidState);
            }

            if ($request->query('error') !== null) {
                throw new GoogleLoginRejected(GoogleLoginRejection::Denied);
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw new GoogleLoginRejected(GoogleLoginRejection::ExchangeFailed);
            }

            $email = $google->identify($code, $pending['verifier'], $pending['nonce']);
            $user = $google->resolveUser($email);
        } catch (GoogleLoginRejected $rejection) {
            $this->recordRejection($request, $rejection);

            return $this->fail($rejection->userMessage());
        } catch (GoogleUnavailable $exception) {
            report($exception);

            return $this->fail($exception->userMessage());
        }

        $this->acceptInvitation($user);

        if (self::hasTwoFactor($user)) {
            // Como RedirectIfTwoFactorAuthenticatable de Fortify: el reto termina el inicio de sesión.
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => $pending['remember']]);
            TwoFactorAuthenticationChallenged::dispatch($user);
            $request->session()->put(self::GOOGLE_USER, $user->id);

            GoogleLoginAudit::accepted($user, $email, twoFactorPending: true);

            return redirect()->route('two-factor.login');
        }

        $request->session()->put(self::GOOGLE_USER, $user->id);
        Auth::guard((string) config('fortify.guard', 'web'))->login($user, $pending['remember']);
        $request->session()->regenerate();

        GoogleLoginAudit::accepted($user, $email, twoFactorPending: false);

        return redirect()->intended(Fortify::redirects('login'));
    }

    /**
     * Lo guardado al salir hacia Google si el `state` de la vuelta coincide y no ha caducado
     * (protección CSRF del flujo OAuth); si no, null.
     *
     * @return array{verifier: string, nonce: string, remember: bool}|null
     */
    private function pending(mixed $pending, mixed $state): ?array
    {
        $valid = is_array($pending)
            && is_string($pending['state'] ?? null)
            && is_string($pending['verifier'] ?? null)
            && is_string($pending['nonce'] ?? null)
            && is_int($pending['expires_at'] ?? null)
            && $pending['expires_at'] >= now()->getTimestamp()
            && is_string($state)
            && hash_equals($pending['state'], $state);

        return $valid ? [
            'verifier' => $pending['verifier'],
            'nonce' => $pending['nonce'],
            'remember' => ($pending['remember'] ?? false) === true,
        ] : null;
    }

    /** ¿Tiene el 2FA propio confirmado? (La misma condición que Fortify con `confirm`.) */
    private static function hasTwoFactor(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Google ya ha verificado el correo: si tenía la invitación de alta pendiente, queda aceptada
     * (se borra el enlace) y el correo, verificado. La contraseña sigue sin fijar: si algún día la
     * necesita, «¿Has olvidado tu contraseña?».
     */
    private function acceptInvitation(User $user): void
    {
        Password::broker(InvitationController::BROKER)->deleteToken($user);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    /**
     * Registro de accesos y auditoría de una cuenta de Google identificada que no puede entrar.
     * Sin correo (state caducado, cancelado…) no hay a quién apuntarlo: solo cuenta el límite.
     */
    private function recordRejection(Request $request, GoogleLoginRejected $rejection): void
    {
        if ($rejection->email === null) {
            return;
        }

        LoginEvent::query()->create([
            'user_id' => $rejection->user?->id,
            'email' => Str::limit($rejection->email, 255, ''),
            'method' => LoginEvent::METHOD_GOOGLE,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'succeeded' => false,
        ]);

        GoogleLoginAudit::rejected($rejection);
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
