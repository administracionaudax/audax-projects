<?php

namespace App\Enums;

/**
 * Estado de una exportación de datos personales (D-075).
 */
enum PersonalDataExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return __("privacy.exports.status.{$this->value}");
    }

    /** Todavía no ha terminado (no se puede pedir otra para la misma persona). */
    public function inProgress(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
