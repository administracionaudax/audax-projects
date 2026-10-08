<?php

namespace App\Domain\Billing\Holded;

use RuntimeException;

/**
 * Error de la API de Holded con un mensaje claro en español (Fase 12, D-384). Nunca lleva la clave
 * ni las cabeceras: solo el código HTTP y la ruta pedida.
 */
class HoldedRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $path = null)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self((string) __('billing.holded.errors.not_configured'));
    }

    public static function forStatus(int $status, string $path): self
    {
        $key = match (true) {
            $status === 401 => 'unauthorized',
            $status === 402 => 'plan',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server',
            default => 'unexpected',
        };

        return new self((string) __("billing.holded.errors.{$key}", ['status' => $status, 'path' => $path]), $status, $path);
    }

    public static function connection(string $path): self
    {
        return new self((string) __('billing.holded.errors.connection', ['path' => $path]), null, $path);
    }

    public static function invalidPdf(string $path): self
    {
        return new self((string) __('billing.holded.errors.invalid_pdf', ['path' => $path]), null, $path);
    }
}
