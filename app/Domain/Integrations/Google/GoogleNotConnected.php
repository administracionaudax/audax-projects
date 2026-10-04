<?php

namespace App\Domain\Integrations\Google;

use RuntimeException;

/**
 * La persona no tiene su cuenta de Google conectada (D-142): la exportación responde 409 y la
 * interfaz le manda a Ajustes → Integraciones.
 */
class GoogleNotConnected extends RuntimeException
{
    public function userMessage(): string
    {
        $message = __('integrations.google.errors.not_connected');

        return is_string($message) ? $message : 'not_connected';
    }
}
