<?php

namespace App\Enums;

/**
 * En qué se cuenta un tipo de ausencia del catálogo (Fase 11, R3; W-056; D-361):
 * - `working_days`: días laborables (los de su jornada, sin festivos; un día de media jornada vale
 *   medio día). Las cantidades se guardan en **centésimas de día** (2200 = 22 días).
 * - `calendar_days`: días naturales, todos los del periodo (también en centésimas).
 * - `hours`: horas, en **minutos** enteros (como el resto de la app); se piden con su franja.
 */
enum LeaveUnit: string
{
    case WorkingDays = 'working_days';
    case CalendarDays = 'calendar_days';
    case Hours = 'hours';

    public function isDays(): bool
    {
        return $this !== self::Hours;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
