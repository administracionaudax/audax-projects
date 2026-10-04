<?php

namespace App\Domain\Integrations\Google;

use RuntimeException;

/**
 * Google no responde o responde con un error que no es de la persona (5xx, cuota, red): la
 * exportación responde 502 y se puede reintentar (D-142).
 */
final class GoogleUnavailable extends RuntimeException
{
    public function userMessage(): string
    {
        $message = __('integrations.google.errors.unavailable');

        return is_string($message) ? $message : 'unavailable';
    }
}
