<?php

namespace App\Broadcasting;

/**
 * ¿Hay tiempo real? (config/realtime.php: BROADCAST_CONNECTION=reverb). Sin él, ningún navegador
 * escucha (la prop `realtime` es null y la interfaz consulta cada poco), así que no se encola
 * ningún evento de broadcast: ni trabajo inútil para Horizon ni sorpresas en la cola.
 */
final class Realtime
{
    public static function enabled(): bool
    {
        return (bool) config('realtime.enabled');
    }
}
