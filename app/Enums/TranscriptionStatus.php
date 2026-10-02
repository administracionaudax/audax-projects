<?php

namespace App\Enums;

/**
 * Estado de la transcripción de un audio (SPEC §12): todo audio acaba en done; failed se reintenta.
 */
enum TranscriptionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
