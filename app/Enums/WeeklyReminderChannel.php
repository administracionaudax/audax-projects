<?php

namespace App\Enums;

/**
 * Canal de una regla de recordatorio de la weekly (F-101 y F-102).
 */
enum WeeklyReminderChannel: string
{
    case Email = 'email';
    case Push = 'push';

    public function label(): string
    {
        return __("weeklies.enums.reminder_channel.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
