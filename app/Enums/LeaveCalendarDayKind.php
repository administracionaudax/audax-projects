<?php

namespace App\Enums;

/**
 * Días especiales del calendario laboral (Fase 11, R3; W-034 y W-039; D-366):
 * - `half_day`: media jornada (la jornada teórica es la mitad y unas vacaciones descuentan medio
 *   día),
 * - `blocked`: no se pueden pedir vacaciones (las de los tipos que respetan los días bloqueados).
 */
enum LeaveCalendarDayKind: string
{
    case HalfDay = 'half_day';
    case Blocked = 'blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
