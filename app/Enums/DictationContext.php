<?php

namespace App\Enums;

/**
 * Dónde se usa un dictado (D-152): un apunte de la weekly (F-049) o las notas de una tarea (F-060).
 */
enum DictationContext: string
{
    case WeeklyEntry = 'weekly_entry';
    case TaskNote = 'task_note';

    public function label(): string
    {
        return __("weeklies.enums.dictation_context.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
