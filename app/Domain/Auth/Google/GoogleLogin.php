<?php

namespace App\Domain\Auth\Google;

use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleUnavailable;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Acceso con Google (D-165): OpenID Connect con el mismo cliente OAuth de Google Sheets (D-142) y
 * el cliente HTTP de Laravel, sin dependencias nuevas.
 *
 * - Autorización con los alcances mínimos (`openid email profile`), `state` anti-CSRF, `nonce`,
 *   PKCE (S256), `prompt=select_account` y `hd` como pista si solo hay un dominio permitido.
 * - Vuelta: cambia el código por el id_token y lo valida en el servidor (emisor, audiencia,
 *   caducidad, `nonce`, correo verificado, dominio permitido y `hd` igual al dominio del correo:
 *   solo cuentas gestionadas por Google Workspace, nunca una cuenta personal con ese correo).
 * - Solo entra quien ya existe en la app con ese correo (`lower(email)`), activo, de la plantilla
 *   y que no sea colaborador externo. Nunca se crean usuarios.
 *
 * No se piden ni se guardan tokens de Google: solo se lee la identidad.
 */
final class GoogleLogin
{
    /** @var list<string> */
    public const array SCOPES = ['openid', 'email', 'profile'];

    /** ¿Se ofrece «Entrar con Google»? Con credenciales y el ajuste activado (por defecto, sí). */
    public static function available(): bool
    {
        return GoogleOAuth::configured() && (bool) Setting::get('google_login_enabled', true);
    }

    /**
     * Dominios de Workspace que pueden entrar (GOOGLE_LOGIN_DOMAINS, separados por comas), en
     * minúsculas. Si no hay ninguno válido, el de Google Sheets (GOOGLE_HOSTED_DOMAIN).
     *
     * @return list<string>
     */
    public static function allowedDomains(): array
    {
        $configured = config('services.google.login_domains');
        $values = is_array($configured) ? $configured : explode(',', is_string($configured) ? $configured : '');

        $domains = [];

        foreach ($values as $value) {
            $domain = is_string($value) ? Str::lower(ltrim(trim($value), '@')) : '';

            if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1) {
                $domains[] = $domain;
            }
        }

        return $domains === [] ? [app(GoogleOAuth::class)->hostedDomain()] : array_values(array_unique($domains));
    }

    /** ¿Es el correo de un dominio permitido? (Para ofrecer el botón en la invitación.) */
    public static function allowsEmail(string $email): bool
    {
        return in_array(Str::lower(Str::afterLast($email, '@')), self::allowedDomains(), true);
    }

    /** URI de redirección: la de GOOGLE_LOGIN_REDIRECT_URI o, si falta, la ruta del callback. */
    public function redirectUri(): string
    {
        $uri = config('services.google.login_redirect');

        return is_string($uri) && $uri !== '' ? $uri : route('login.google.callback');
    }

    /**
     * URL de autorización y los secretos de un solo uso que el callback debe comprobar (se guardan
     * en la sesión).
     *
     * @return array{url: string, state: string, verifier: string, nonce: string}
     */
    public function authorization(): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $nonce = Str::random(40);
        $domains = self::allowedDomains();

        $query = http_build_query([
            'client_id' => GoogleOAuth::clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => GoogleOAuth::challenge($verifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            // Pista para el selector de cuentas de Google (solo admite un dominio); la validación de
            // verdad la hace identify().
            ...(count($domains) === 1 ? ['hd' => $domains[0]] : []),
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => GoogleOAuth::AUTHORIZE_URL.'?'.$query, 'state' => $state, 'verifier' => $verifier, 'nonce' => $nonce];
    }

    /**
     * Cambia el código por el id_token y devuelve el correo verificado de la cuenta de Google, en
     * minúsculas.
     *
     * @throws GoogleLoginRejected
     * @throws GoogleUnavailable
     */
    public function identify(string $code, string $verifier, string $nonce): string
    {
        $oauth = app(GoogleOAuth::class);

        $response = $oauth->send(fn (PendingRequest $http) => $http->post(GoogleOAuth::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => GoogleOAuth::clientId(),
            'client_secret' => GoogleOAuth::clientSecret(),
        ]));

        if ($response->serverError()) {
            throw new GoogleUnavailable('Google token endpoint: '.$response->status());
        }

        if (! $response->successful()) {
            throw new GoogleLoginRejected(GoogleLoginRejection::ExchangeFailed);
        }

        $claims = GoogleOAuth::idTokenClaims($response->json('id_token'));

        if ($claims === null || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new GoogleLoginRejected(GoogleLoginRejection::InvalidToken);
        }

        $email = is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : '';

        if ($email === '' || ! str_contains($email, '@')) {
            throw new GoogleLoginRejected(GoogleLoginRejection::InvalidToken);
        }

        if (! GoogleOAuth::emailVerified($claims)) {
            throw new GoogleLoginRejected(GoogleLoginRejection::Unverified, $email);
        }

        // `hd` solo lo llevan las cuentas de Google Workspace: tiene que ser el dominio del correo.
        $domain = Str::afterLast($email, '@');
        $hostedDomain = is_string($claims['hd'] ?? null) ? Str::lower($claims['hd']) : '';

        if (! in_array($domain, self::allowedDomains(), true) || $hostedDomain !== $domain) {
            throw new GoogleLoginRejected(GoogleLoginRejection::WrongDomain, $email);
        }

        return $email;
    }

    /**
     * La persona de la app con ese correo, si puede entrar con Google.
     *
     * @throws GoogleLoginRejected
     */
    public function resolveUser(string $email): User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if ($user === null) {
            throw new GoogleLoginRejected(GoogleLoginRejection::NotFound, $email);
        }

        $reason = match (true) {
            ! $user->isActive() => GoogleLoginRejection::Inactive,
            $user->isClient() => GoogleLoginRejection::Client,
            $user->isCollaborator() => GoogleLoginRejection::Collaborator,
            default => null,
        };

        if ($reason !== null) {
            throw new GoogleLoginRejected($reason, $email, $user);
        }

        return $user;
    }
}
