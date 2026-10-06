<?php

namespace App\Enums;

/**
 * Motivo de «Estoy fuera» en la Weekly (10.9b, D-228): el VACATION/ABSENT de WeeklySync.
 */
enum WeeklyAwayReason: string
{
    case Vacation = 'vacation';
    case Absent = 'absent';

    public function label(): string
    {
        return __("weeklies.enums.away_reason.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
