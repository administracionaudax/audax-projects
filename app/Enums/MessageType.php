<?php

namespace App\Enums;

/**
 * Tipo de mensaje del chat. Los de sistema no tienen autor (bolsa al 90 %, hito completado…).
 */
enum MessageType: string
{
    case Text = 'text';
    case Audio = 'audio';
    case File = 'file';
    case System = 'system';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
