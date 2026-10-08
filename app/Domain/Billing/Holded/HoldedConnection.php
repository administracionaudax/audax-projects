<?php

namespace App\Domain\Billing\Holded;

/**
 * Estado de la conexión con Holded (Fase 12, D-384), sin enseñar nunca la clave: si es el Holded
 * falso, si hay clave y la URL de la API.
 */
final class HoldedConnection
{
    public static function fake(): bool
    {
        return config('services.holded.driver') === 'fake';
    }

    /** ¿Se puede sincronizar? Con el falso, siempre; con el real, si hay clave. */
    public static function configured(): bool
    {
        return self::fake() || trim((string) config('services.holded.key')) !== '';
    }

    /**
     * @return array{driver: string, configured: bool, base_url: string, per_minute: int}
     */
    public static function summary(): array
    {
        return [
            'driver' => self::fake() ? 'fake' : 'holded',
            'configured' => self::configured(),
            'base_url' => (string) config('services.holded.base_url'),
            'per_minute' => (int) config('services.holded.per_minute', 60),
        ];
    }
}
