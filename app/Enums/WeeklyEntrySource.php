<?php

namespace App\Enums;

/**
 * Origen del texto de un apunte de la weekly: escrito o dictado (F-049).
 */
enum WeeklyEntrySource: string
{
    case Text = 'text';
    case Dictation = 'dictation';

    public function label(): string
    {
        return __("weeklies.enums.entry_source.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
