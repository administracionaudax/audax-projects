<?php

namespace App\Domain\Billing\Recurring;

use App\Models\RecurringInvoice;
use Carbon\CarbonImmutable;

/**
 * El calendario de una factura recurrente (E3), sin base de datos:
 *
 * - Las fechas caen cada `interval_months` meses contando desde el mes de inicio, el día
 *   `day_of_month`; si el mes es más corto, su último día (el 31 en abril es el 30; el 29, 30 o 31
 *   en febrero es el 28, o el 29 en un año bisiesto). El mes siguiente vuelve al día elegido.
 * - La primera es la primera de esas fechas que no es anterior al inicio.
 * - Se acaba al pasar la fecha de fin o al llegar al número de veces (se cuentan las generadas: un
 *   periodo omitido no gasta una vez).
 */
final class RecurringSchedule
{
    /** La primera fecha que toca desde un día (incluido). */
    public static function firstOnOrAfter(CarbonImmutable $start, int $interval, int $day, CarbonImmutable $from): CarbonImmutable
    {
        $interval = max(1, $interval);
        $anchor = $start->startOfMonth();
        $months = max(0, ($from->year - $anchor->year) * 12 + ($from->month - $anchor->month));
        $step = intdiv($months, $interval);
        $date = self::nth($anchor, $interval, $day, $step);

        while ($date->toDateString() < $from->toDateString() || $date->toDateString() < $start->toDateString()) {
            $date = self::nth($anchor, $interval, $day, ++$step);
        }

        return $date;
    }

    /** La fecha que sigue a una dada (la siguiente del calendario, después de ella). */
    public static function after(CarbonImmutable $start, int $interval, int $day, CarbonImmutable $date): CarbonImmutable
    {
        return self::firstOnOrAfter($start, $interval, $day, $date->addDay());
    }

    /** La fecha número `step` (0, 1, 2…) desde el mes de inicio. */
    public static function nth(CarbonImmutable $anchor, int $interval, int $day, int $step): CarbonImmutable
    {
        $month = $anchor->startOfMonth()->addMonthsNoOverflow($step * max(1, $interval));

        return $month->setDay(min(max(1, $day), $month->daysInMonth));
    }

    /** La primera de una recurrente (al crearla o cambiar su calendario). */
    public static function first(RecurringInvoice $recurring, ?CarbonImmutable $from = null): ?CarbonImmutable
    {
        $date = self::firstOnOrAfter($recurring->start_date, $recurring->interval_months, $recurring->day_of_month, $from ?? $recurring->start_date);

        return self::withinLimits($recurring, $date, $recurring->occurrences_count) ? $date : null;
    }

    /** La siguiente después de un periodo, o null si con ella se acaba. */
    public static function next(RecurringInvoice $recurring, CarbonImmutable $period, int $generated): ?CarbonImmutable
    {
        $date = self::after($recurring->start_date, $recurring->interval_months, $recurring->day_of_month, $period);

        return self::withinLimits($recurring, $date, $generated) ? $date : null;
    }

    /**
     * Las próximas fechas (la ficha enseña las 12 siguientes), con las omitidas marcadas.
     *
     * @return list<array{date: string, skipped: bool}>
     */
    public static function upcoming(RecurringInvoice $recurring, int $count = 12): array
    {
        $date = $recurring->next_run_on;
        $generated = $recurring->occurrences_count;
        $list = [];

        while ($date !== null && count($list) < $count) {
            $skipped = $recurring->isSkipped($date);
            $list[] = ['date' => $date->toDateString(), 'skipped' => $skipped];
            if (! $skipped) {
                $generated++;
            }
            $date = self::next($recurring, $date, $generated);
        }

        return $list;
    }

    private static function withinLimits(RecurringInvoice $recurring, CarbonImmutable $date, int $generated): bool
    {
        if ($recurring->end_date !== null && $date->toDateString() > $recurring->end_date->toDateString()) {
            return false;
        }

        return $recurring->max_occurrences === null || $generated < $recurring->max_occurrences;
    }
}
