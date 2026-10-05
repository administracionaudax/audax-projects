<?php

namespace App\Enums;

/**
 * Resultado de un envío de recordatorio (F-108): en cola hasta que el canal lo entrega (10.5, D-201),
 * enviado, fallido (con el error) u omitido (sin canal o desactivado por la persona).
 */
enum WeeklyReminderStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __("weeklies.enums.reminder_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
