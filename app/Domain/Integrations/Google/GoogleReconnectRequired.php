<?php

namespace App\Domain\Integrations\Google;

/**
 * Google ha retirado el acceso (invalid_grant al renovar, o rechaza el token recién renovado): la
 * conexión ya se ha borrado y hay que volver a conectar (D-142).
 */
final class GoogleReconnectRequired extends GoogleNotConnected
{
    public function userMessage(): string
    {
        $message = __('integrations.google.errors.reconnect');

        return is_string($message) ? $message : 'reconnect';
    }
}
