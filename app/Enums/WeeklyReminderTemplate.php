<?php

namespace App\Enums;

/**
 * Plantilla de aviso de la weekly (F-104 y F-105), editable en ajustes (setting weekly_email_templates).
 */
enum WeeklyReminderTemplate: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case WeeklyClosed = 'weekly_closed';

    public function label(): string
    {
        return __("weeklies.enums.reminder_template.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
