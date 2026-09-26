<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * Periodo de un informe (SPEC §10, filtros globales). El valor es el de la URL, en español.
 */
enum ReportPeriod: string
{
    case Week = 'semana';
    case Month = 'mes';
    case Quarter = 'trimestre';
    case Year = 'anio';
    case Range = 'rango';

    /**
     * Inicio y fin (fechas locales, ambos incluidos) del periodo que contiene $anchor.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(CarbonImmutable $anchor): array
    {
        $day = $anchor->startOfDay();

        return match ($this) {
            self::Week => [$day->startOfWeek(), $day->startOfWeek()->addDays(6)],
            self::Month => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            self::Quarter => [$day->startOfQuarter(), $day->endOfQuarter()->startOfDay()],
            self::Year => [$day->startOfYear(), $day->endOfYear()->startOfDay()],
            self::Range => [$day, $day],
        };
    }

    /**
     * Mueve el ancla $steps periodos (negativo = hacia atrás).
     */
    public function shift(CarbonImmutable $anchor, int $steps): CarbonImmutable
    {
        return match ($this) {
            self::Week => $anchor->addWeeks($steps),
            self::Month => $anchor->startOfMonth()->addMonthsNoOverflow($steps),
            self::Quarter => $anchor->startOfQuarter()->addMonthsNoOverflow(3 * $steps),
            self::Year => $anchor->startOfYear()->addYears($steps),
            self::Range => $anchor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Semana',
            self::Month => 'Mes',
            self::Quarter => 'Trimestre',
            self::Year => 'Año',
            self::Range => 'Rango',
        };
    }
}
