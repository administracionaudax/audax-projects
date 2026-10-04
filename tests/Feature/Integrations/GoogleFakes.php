<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Google\GoogleOAuth;
use App\Http\Controllers\Integrations\IntegrationsController;
use App\Models\GoogleConnection;
use App\Models\User;

/**
 * Datos falsos de Google para los tests de la Fase 9 (D-142). Nunca se llama a Google: Http::fake.
 */
final class GoogleFakes
{
    public const string CLIENT_ID = 'cliente-falso.apps.googleusercontent.com';

    public const string REDIRECT_URI = 'https://projects.audaxstudio.com/integraciones/google/callback';

    /** Credenciales ficticias: con ellas, la integración se ofrece. */
    public static function configure(): void
    {
        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.client_secret' => 'secreto-falso',
            'services.google.redirect' => self::REDIRECT_URI,
            'services.google.hosted_domain' => 'audaxstudio.com',
        ]);
    }

    /**
     * id_token de Google (JWT) con las claims indicadas. La firma no se comprueba: el token llega
     * directamente del endpoint de tokens por TLS.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function idToken(array $claims = []): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'exp' => now()->addHour()->getTimestamp(),
            'email' => 'elena@audaxstudio.com',
            'email_verified' => true,
            'hd' => 'audaxstudio.com',
            ...$claims,
        ]).'.firma-falsa';
    }

    /**
     * Respuesta del endpoint de tokens al cambiar el código.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function tokenResponse(array $overrides = []): array
    {
        return [
            'access_token' => 'ya29.acceso-nuevo',
            'refresh_token' => '1//refresco-nuevo',
            'expires_in' => 3599,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email '.GoogleOAuth::DRIVE_FILE_SCOPE,
            'token_type' => 'Bearer',
            'id_token' => self::idToken(),
            ...$overrides,
        ];
    }

    /**
     * Sesión con un flujo OAuth en curso.
     *
     * @return array<string, array{state: string, verifier: string, expires_at: int}>
     */
    public static function pendingSession(string $state = 'estado-valido', ?int $expiresAt = null): array
    {
        return [IntegrationsController::SESSION_KEY => [
            'state' => $state,
            'verifier' => 'verificador-pkce',
            'expires_at' => $expiresAt ?? now()->addMinutes(5)->getTimestamp(),
        ]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function connect(User $user, array $attributes = []): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'user_id' => $user->id,
            'google_email' => 'elena@audaxstudio.com',
            'refresh_token' => '1//refresco-guardado',
            'access_token' => 'ya29.acceso-guardado',
            'expires_at' => now()->addHour(),
            'scopes' => 'openid email '.GoogleOAuth::DRIVE_FILE_SCOPE,
            ...$attributes,
        ]);
    }
}
