<?php

namespace App\Domain\Integrations\Google;

use RuntimeException;

/**
 * La vuelta de Google no sirve para conectar la cuenta (D-142): `state` que no es el de la sesión,
 * cuenta de otro dominio, sin el permiso de Drive o sin token de refresco. $reason es la clave del
 * mensaje en lang/es/integrations.php (google.errors.*).
 */
final class GoogleAuthorizationFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Google OAuth: {$reason}");
    }

    public function userMessage(): string
    {
        $message = __("integrations.google.errors.{$this->reason}", ['domain' => app(GoogleOAuth::class)->hostedDomain()]);

        return is_string($message) ? $message : $this->reason;
    }
}
