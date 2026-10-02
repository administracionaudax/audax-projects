<?php

namespace App\Broadcasting;

/**
 * Configuración de Web Push (D-072, config/services.php → webpush).
 *
 * - Activo solo con claves VAPID VÁLIDAS (pública de 65 bytes sin comprimir y privada de 32, en
 *   base64url) y un «subject» mailto: o https:. Con los valores de ejemplo de .env.example, o sin
 *   ellos, el canal queda desactivado sin errores.
 * - Solo se envía a los servicios de push de los navegadores (allowed_hosts, por https y en el
 *   puerto estándar): el endpoint lo manda el navegador y nunca puede llevar al servidor a
 *   llamar a otro sitio (SSRF).
 */
final class WebPushConfig
{
    public const int PUBLIC_KEY_BYTES = 65;

    public const int PRIVATE_KEY_BYTES = 32;

    public const int AUTH_BYTES = 16;

    public static function enabled(): bool
    {
        return self::vapid() !== null;
    }

    /**
     * Clave pública (base64url) que el navegador necesita para suscribirse, o null si está apagado.
     */
    public static function publicKey(): ?string
    {
        return self::vapid()['publicKey'] ?? null;
    }

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    public static function vapid(): ?array
    {
        return self::fromValues(
            config('services.webpush.public_key'),
            config('services.webpush.private_key'),
            config('services.webpush.subject'),
        );
    }

    /**
     * Las mismas comprobaciones con valores sueltos: config/notifications.php decide con ellas, al
     * cargar la configuración, si el canal de Web Push existe (D-073).
     *
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    public static function fromValues(mixed $public, mixed $private, mixed $subject): ?array
    {
        if (! is_string($public) || ! is_string($private) || ! is_string($subject)) {
            return null;
        }

        $publicBytes = self::decode($public);
        $privateBytes = self::decode($private);

        if ($publicBytes === null || strlen($publicBytes) !== self::PUBLIC_KEY_BYTES || $publicBytes[0] !== "\x04"
            || $privateBytes === null || strlen($privateBytes) !== self::PRIVATE_KEY_BYTES
            || (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://'))) {
            return null;
        }

        return ['subject' => $subject, 'publicKey' => $public, 'privateKey' => $private];
    }

    /**
     * ¿Es la URL de un servicio de push de navegador admitido?
     */
    public static function allowsEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        /** @var list<string> $allowed */
        $allowed = (array) config('services.webpush.allowed_hosts', []);

        foreach ($allowed as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Es una clave de suscripción del navegador bien formada (p256dh y auth)?
     */
    public static function validSubscriptionKeys(string $publicKey, string $authToken): bool
    {
        $public = self::decode($publicKey);
        $auth = self::decode($authToken);

        return $public !== null && strlen($public) === self::PUBLIC_KEY_BYTES && $public[0] === "\x04"
            && $auth !== null && strlen($auth) === self::AUTH_BYTES;
    }

    /**
     * base64url (con o sin relleno) → bytes; null si no es base64url.
     */
    public static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
