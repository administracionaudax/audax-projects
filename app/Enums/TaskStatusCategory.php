<?php

namespace App\Enums;

/**
 * Categoría de un estado de tarea (SPEC §4.3): los cálculos usan la categoría, nunca el nombre.
 */
enum TaskStatusCategory: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'Por hacer',
            self::InProgress => 'En curso',
            self::Done => 'Hecha',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
