<?php

namespace App\Enums;

/**
 * Canal de una regla de recordatorio de la weekly (F-101 y F-102) y de cada fila del registro: la
 * campana de la app (10.5, D-199), el email (cola `mail`) o Web Push. Son los canales lógicos de
 * NotificationCatalog.
 */
enum WeeklyReminderChannel: string
{
    case App = 'app';
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
