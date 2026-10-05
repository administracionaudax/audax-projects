<?php

namespace App\Domain\Integrations\Google;

use App\Models\GoogleConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 con Google para exportar a Google Sheets (Fase 9, D-142), con el cliente HTTP de
 * Laravel y sin dependencias nuevas:
 * - autorización con `state` anti-CSRF y PKCE (S256), `access_type=offline` para el token de
 *   refresco, `hd` del dominio de Workspace y los alcances mínimos (openid, email y drive.file),
 * - intercambio del código, comprobación de la cuenta (dominio y correo verificado) y del alcance,
 * - renovación del token de acceso con el de refresco; si Google responde `invalid_grant`, la
 *   conexión se borra y hay que volver a conectar,
 * - revocación al desconectar.
 *
 * Sin client_id o secret configurados, la integración no se ofrece (configured()).
 */
final class GoogleOAuth
{
    public const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const string DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /** @var list<string> */
    public const array SCOPES = ['openid', 'email', self::DRIVE_FILE_SCOPE];

    /** Emisores válidos del id_token de Google. */
    private const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** ¿Están el ID y el secreto del cliente OAuth? Si no, la opción no se ofrece (D-142). */
    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** URI de redirección: la de GOOGLE_REDIRECT_URI o, si falta, la ruta del callback. */
    public function redirectUri(): string
    {
        $uri = config('services.google.redirect');

        return is_string($uri) && $uri !== '' ? $uri : route('integrations.google.callback');
    }

    public function hostedDomain(): string
    {
        $domain = config('services.google.hosted_domain');

        return Str::lower(is_string($domain) && $domain !== '' ? $domain : 'audaxstudio.com');
    }

    /**
     * URL de autorización y los secretos de un solo uso que el callback debe comprobar (se guardan
     * en la sesión). La primera vez (sin conexión) se pide el consentimiento para que Google dé
     * el token de refresco; al reconectar basta con elegir la cuenta.
     *
     * @return array{url: string, state: string, verifier: string}
     */
    public function authorization(bool $firstTime): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $query = http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'prompt' => $firstTime ? 'consent' : 'select_account',
            'hd' => $this->hostedDomain(),
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => self::AUTHORIZE_URL.'?'.$query, 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Cambia el código de la vuelta por los tokens, comprueba la cuenta y el alcance y guarda la
     * conexión de $user (una por persona). Si la cuenta no vale, revoca lo recibido.
     *
     * @throws GoogleAuthorizationFailed
     * @throws GoogleUnavailable
     */
    public function connect(User $user, string $code, string $verifier): GoogleConnection
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
        ]));

        if ($response->serverError()) {
            throw new GoogleUnavailable('Google token endpoint: '.$response->status());
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new GoogleAuthorizationFailed('exchange_failed');
        }

        $refreshToken = $response->json('refresh_token');
        $refreshToken = is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null;

        $email = $this->verifiedEmail($response->json('id_token'));

        if ($email === null) {
            $this->revokeToken($refreshToken ?? $accessToken);

            throw new GoogleAuthorizationFailed('wrong_domain');
        }

        $scopes = self::scopeString($response->json('scope'));

        if (! in_array(self::DRIVE_FILE_SCOPE, explode(' ', $scopes), true)) {
            $this->revokeToken($refreshToken ?? $accessToken);

            throw new GoogleAuthorizationFailed('missing_scope');
        }

        $existing = $user->googleConnection()->first();

        // Al reconectar con la misma cuenta, Google puede no repetir el token de refresco.
        if ($refreshToken === null && $existing !== null && Str::lower($existing->google_email) === $email) {
            $refreshToken = $existing->refresh_token;
        }

        if ($refreshToken === null) {
            throw new GoogleAuthorizationFailed('missing_refresh_token');
        }

        return GoogleConnection::query()->updateOrCreate(['user_id' => $user->id], [
            'google_email' => $email,
            'refresh_token' => $refreshToken,
            'access_token' => $accessToken,
            'expires_at' => self::expiry($response->json('expires_in')),
            'scopes' => $scopes,
        ]);
    }

    /**
     * Token de acceso válido de $user, renovado si ha caducado (o si $force).
     *
     * @throws GoogleNotConnected sin conexión, o GoogleReconnectRequired si Google la ha retirado
     * @throws GoogleUnavailable
     */
    public function accessToken(User $user, bool $force = false): string
    {
        $connection = $user->googleConnection()->first();

        if ($connection === null) {
            throw new GoogleNotConnected('Google account not connected');
        }

        if ($force || $connection->accessTokenExpired()) {
            $connection = $this->refresh($connection);
        }

        return (string) $connection->access_token;
    }

    /**
     * Renueva el token de acceso con el de refresco. Si Google responde `invalid_grant` (la persona
     * quitó el acceso, cambió la contraseña o el token caducó), se borra la conexión.
     *
     * @throws GoogleReconnectRequired
     * @throws GoogleUnavailable
     */
    public function refresh(GoogleConnection $connection): GoogleConnection
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
        ]));

        if ($response->json('error') === 'invalid_grant') {
            GoogleDisconnector::forgetRevoked($connection);

            throw new GoogleReconnectRequired('Google refresh token revoked (invalid_grant)');
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new GoogleUnavailable('Google token refresh: '.$response->status());
        }

        $rotated = $response->json('refresh_token');
        $scopes = $response->json('scope');

        $connection->forceFill([
            'access_token' => $accessToken,
            'expires_at' => self::expiry($response->json('expires_in')),
            ...(is_string($rotated) && $rotated !== '' ? ['refresh_token' => $rotated] : []),
            ...(is_string($scopes) && $scopes !== '' ? ['scopes' => self::scopeString($scopes)] : []),
        ])->save();

        return $connection;
    }

    /**
     * Desconecta: revoca el token en Google y borra la fila. Devuelve si Google confirmó la
     * revocación (la fila se borra igualmente).
     */
    public function disconnect(GoogleConnection $connection): bool
    {
        $revoked = $this->revokeToken($connection->refresh_token);

        $connection->delete();

        return $revoked;
    }

    /** Revoca un token (de refresco o de acceso). Sin excepciones: devuelve si Google lo confirmó. */
    public function revokeToken(string $token): bool
    {
        try {
            return $this->send(fn (PendingRequest $http) => $http->post(self::REVOKE_URL, ['token' => $token]))->successful();
        } catch (GoogleUnavailable) {
            return false;
        }
    }

    /**
     * Correo del id_token si es una cuenta verificada del dominio de Workspace, o null. El token
     * llega directamente del endpoint de tokens de Google por TLS (OpenID Connect Core §3.1.3.7),
     * así que basta con comprobar el emisor, la audiencia, la caducidad, el dominio (`hd`) y el
     * correo.
     */
    private function verifiedEmail(mixed $idToken): ?string
    {
        $payload = self::idTokenClaims($idToken);

        if ($payload === null) {
            return null;
        }

        $email = is_string($payload['email'] ?? null) ? Str::lower($payload['email']) : '';
        $domain = $this->hostedDomain();

        $valid = self::emailVerified($payload)
            && Str::lower((string) ($payload['hd'] ?? '')) === $domain
            && Str::endsWith($email, '@'.$domain);

        return $valid ? $email : null;
    }

    /**
     * Claims del id_token recibido del endpoint de tokens si el emisor es Google, la audiencia es
     * este cliente y no ha caducado; si no, null. También lo usa el acceso con Google (D-165).
     *
     * @return array<string, mixed>|null
     */
    public static function idTokenClaims(mixed $idToken): ?array
    {
        if (! is_string($idToken) || substr_count($idToken, '.') !== 2) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode(explode('.', $idToken)[1]), true);

        if (! is_array($payload)) {
            return null;
        }

        $valid = in_array($payload['iss'] ?? null, self::ISSUERS, true)
            && ($payload['aud'] ?? null) === self::clientId()
            && is_numeric($payload['exp'] ?? null) && (int) $payload['exp'] > now()->getTimestamp();

        /** @var array<string, mixed> $payload */
        return $valid ? $payload : null;
    }

    /**
     * ¿Google da el correo por verificado? (`email_verified` llega como booleano o como texto).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function emailVerified(array $claims): bool
    {
        return ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true';
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws GoogleUnavailable si no hay respuesta (red o tiempo agotado)
     */
    public function send(callable $call): Response
    {
        try {
            return $call(Http::asForm()->acceptJson()->timeout(self::timeout()));
        } catch (ConnectionException $exception) {
            throw new GoogleUnavailable('Google OAuth: '.$exception->getMessage(), previous: $exception);
        }
    }

    private static function expiry(mixed $expiresIn): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(is_numeric($expiresIn) ? (int) $expiresIn : 3600);
    }

    private static function scopeString(mixed $scope): string
    {
        return is_string($scope) ? trim((string) preg_replace('/\s+/', ' ', $scope)) : '';
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    public static function clientId(): string
    {
        $id = config('services.google.client_id');

        return is_string($id) ? trim($id) : '';
    }

    public static function clientSecret(): string
    {
        $secret = config('services.google.client_secret');

        return is_string($secret) ? trim($secret) : '';
    }

    public static function timeout(): int
    {
        return max(5, (int) config('services.google.timeout', 30));
    }
}
