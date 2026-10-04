<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\Integrations\Google\GoogleAuthorizationFailed;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleUnavailable;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Ajustes → Integraciones (/ajustes/integraciones, Fase 9, D-142): cada persona de la plantilla
 * conecta su cuenta de Google de Workspace para exportar informes a Google Sheets. Solo internos
 * sin ser colaboradores externos (D-134): las rutas están en el grupo internal y fuera de
 * config/collaborators.php.
 *
 * - connect: guarda en la sesión el `state` y el verificador PKCE (un solo uso, 10 minutos) y
 *   manda a Google,
 * - callback: comprueba el `state`, cambia el código por los tokens y guarda la conexión,
 * - destroy: revoca en Google y borra la conexión.
 *
 * Sin credenciales (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET), la página dice que no está
 * disponible y conectar o volver de Google responde 404.
 */
class IntegrationsController extends Controller
{
    public const string SESSION_KEY = 'integrations.google.oauth';

    private const int STATE_TTL_SECONDS = 600;

    public function edit(Request $request): Response
    {
        $available = GoogleOAuth::configured();
        $connection = $available ? $this->user($request)->googleConnection()->first() : null;

        return Inertia::render('settings/integrations', [
            'google' => [
                'available' => $available,
                'connection' => $connection === null ? null : [
                    'email' => $connection->google_email,
                    'connected_at' => $connection->created_at?->toIso8601ZuluString(),
                ],
            ],
        ]);
    }

    public function connect(Request $request, GoogleOAuth $oauth): SymfonyResponse
    {
        abort_unless(GoogleOAuth::configured(), 404);

        $authorization = $oauth->authorization(firstTime: ! $this->user($request)->googleConnection()->exists());

        $request->session()->put(self::SESSION_KEY, [
            'state' => $authorization['state'],
            'verifier' => $authorization['verifier'],
            'expires_at' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
        ]);

        return Inertia::location($authorization['url']);
    }

    public function callback(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        abort_unless(GoogleOAuth::configured(), 404);

        // De un solo uso: se saca de la sesión pase lo que pase.
        $pending = $request->session()->pull(self::SESSION_KEY);

        try {
            $verifier = $this->verifiedState($pending, $request->query('state'));

            if ($request->query('error') !== null) {
                throw new GoogleAuthorizationFailed('denied');
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw new GoogleAuthorizationFailed('exchange_failed');
            }

            $connection = $oauth->connect($this->user($request), $code, $verifier);
        } catch (GoogleAuthorizationFailed $exception) {
            return $this->back('error', $exception->userMessage());
        } catch (GoogleUnavailable $exception) {
            report($exception);

            return $this->back('error', $exception->userMessage());
        }

        return $this->back('success', $this->text('integrations.google.connected', ['email' => $connection->google_email]));
    }

    public function destroy(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $connection = $this->user($request)->googleConnection()->first();

        if ($connection === null) {
            return to_route('integrations.edit');
        }

        $revoked = $oauth->disconnect($connection);

        return $this->back('success', $this->text($revoked ? 'integrations.google.disconnected' : 'integrations.google.disconnected_unconfirmed'));
    }

    /**
     * El verificador PKCE si el `state` de la vuelta es el que se guardó en la sesión y no ha
     * caducado (protección CSRF del flujo OAuth).
     */
    private function verifiedState(mixed $pending, mixed $state): string
    {
        $valid = is_array($pending)
            && is_string($pending['state'] ?? null)
            && is_string($pending['verifier'] ?? null)
            && is_int($pending['expires_at'] ?? null)
            && $pending['expires_at'] >= now()->getTimestamp()
            && is_string($state)
            && hash_equals($pending['state'], $state);

        if (! $valid) {
            throw new GoogleAuthorizationFailed('invalid_state');
        }

        return $pending['verifier'];
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('integrations.edit');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
