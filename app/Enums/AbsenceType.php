<?php

namespace App\Enums;

/**
 * Tipo de ausencia (SPEC §4.1).
 */
enum AbsenceType: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Leave = 'leave';
    case Training = 'training';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacaciones',
            self::Sick => 'Baja',
            self::Leave => 'Permiso',
            self::Training => 'Formación externa',
            self::Other => 'Otro',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
