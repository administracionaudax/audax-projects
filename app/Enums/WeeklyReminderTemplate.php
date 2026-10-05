<?php

namespace App\Enums;

/**
 * Tipo de aviso de la weekly y su plantilla (F-104 y F-105). Las tres de WeeklySync (automatic,
 * manual y weekly_closed) se editan en «Avisos de la Weekly» (setting weekly_email_templates); las
 * dos nuevas de la 10.5 (D-199 y D-200) tienen un texto fijo y solo aparecen en el registro:
 * - deadline: el plazo de la semana activa ha cambiado,
 * - friday: la parte de la weekly del recordatorio de los viernes (time:remind-week).
 */
enum WeeklyReminderTemplate: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case WeeklyClosed = 'weekly_closed';
    case Deadline = 'deadline';
    case Friday = 'friday';

    /** Las que se editan, en el orden de la pantalla. */
    public const array EDITABLE = ['automatic', 'manual', 'weekly_closed'];

    public function label(): string
    {
        return __("weeklies.enums.reminder_template.{$this->value}");
    }

    public function isEditable(): bool
    {
        return in_array($this->value, self::EDITABLE, true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
