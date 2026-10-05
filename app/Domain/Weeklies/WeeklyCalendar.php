<?php

namespace App\Domain\Weeklies;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calendario de la weekly (D-150, F-070): cada semana va de lunes a viernes, con el plazo el viernes,
 * el número «Wnn-aa» y la etiqueta «Semana nn (Lun dd/mm - Vie dd/mm)» de WeeklySync
 * (`ws:close-week-and-update-satisfaction/index.ts:366-395`).
 *
 * Diferencia deliberada (D-153): el año del número es el AÑO ISO de la semana, no el año natural del
 * viernes. Solo cambia cuando el viernes cae en enero y la semana ISO es aún del año anterior (p. ej.
 * del 28/12/2026 al 01/01/2027: WeeklySync diría W53-27; aquí, W53-26).
 *
 * Lógica pura: sin base de datos. Los días se interpretan en Europe/Madrid.
 */
final class WeeklyCalendar
{
    public const string TIMEZONE = 'Europe/Madrid';

    /**
     * Semana (de lunes a viernes) que contiene ese día. Un sábado o un domingo dan la semana que
     * acaba de terminar (la de su lunes ISO).
     */
    public function periodFor(CarbonInterface|string $date): WeeklyPeriod
    {
        $day = $this->day($date);
        $monday = $day->subDays($day->dayOfWeekIso - 1);

        return $this->periodStarting($monday);
    }

    /**
     * Semana en curso ahora mismo en Madrid.
     */
    public function current(?CarbonInterface $now = null): WeeklyPeriod
    {
        return $this->periodFor(CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE));
    }

    /**
     * La semana siguiente (+7 días), la que se abre al cerrar una (F-070).
     */
    public function next(WeeklyPeriod|CarbonInterface|string $start): WeeklyPeriod
    {
        $monday = $start instanceof WeeklyPeriod ? $start->start : $this->periodFor($start)->start;

        return $this->periodStarting($monday->addDays(7));
    }

    public function previous(WeeklyPeriod|CarbonInterface|string $start): WeeklyPeriod
    {
        $monday = $start instanceof WeeklyPeriod ? $start->start : $this->periodFor($start)->start;

        return $this->periodStarting($monday->subDays(7));
    }

    /**
     * «W41-26»: semana ISO del viernes y los dos últimos dígitos de su año ISO.
     */
    public function number(CarbonInterface|string $date): string
    {
        $friday = $this->periodStartingRaw($date)->addDays(4);

        return sprintf('W%02d-%02d', (int) $friday->format('W'), (int) $friday->format('o') % 100);
    }

    /**
     * «Semana 41 (Lun 05/10 - Vie 09/10)».
     */
    public function label(CarbonInterface|string $date): string
    {
        $monday = $this->periodStartingRaw($date);
        $friday = $monday->addDays(4);

        return __('weeklies.cycle_label', [
            'week' => $friday->format('W'),
            'start' => $monday->format('d/m'),
            'end' => $friday->format('d/m'),
        ]);
    }

    private function periodStarting(CarbonImmutable $monday): WeeklyPeriod
    {
        $friday = $monday->addDays(4);

        return new WeeklyPeriod(
            start: $monday,
            end: $friday,
            deadline: $friday,
            number: $this->number($monday),
            label: $this->label($monday),
        );
    }

    /** Lunes de la semana del día dado. */
    private function periodStartingRaw(CarbonInterface|string $date): CarbonImmutable
    {
        $day = $this->day($date);

        return $day->subDays($day->dayOfWeekIso - 1);
    }

    /**
     * El día (00:00 en Madrid) de una fecha "Y-m-d" o de un instante pasado a Madrid. Una fecha sin
     * hora de un cast `date` es la medianoche UTC (app.timezone), que en Madrid sigue siendo ese día.
     */
    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        $day = is_string($date)
            ? substr($date, 0, 10)
            : CarbonImmutable::instance($date)->setTimezone(self::TIMEZONE)->toDateString();

        return CarbonImmutable::parse($day, self::TIMEZONE)->startOfDay();
    }
}
