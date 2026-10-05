<?php

namespace App\Enums;

/**
 * Resultado de un envío de recordatorio (F-108).
 */
enum WeeklyReminderStatus: string
{
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
