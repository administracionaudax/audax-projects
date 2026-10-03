<?php

namespace App\Domain\Reports\Delivery;

use App\Models\ReportSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Próximo envío de una programación (D-141). Las reglas son de hora local de Madrid (el lunes a
 * las 08:00 es siempre a las 08:00, en invierno y en verano); el resultado, un instante en UTC.
 * - Una vez: su fecha y hora, si aún no ha pasado.
 * - Semanal: el siguiente día de la semana a esa hora, estrictamente después de $after.
 * - Mensual: el día del mes (0 = el último) a esa hora, estrictamente después de $after.
 * Una hora que no existe el día del cambio al horario de verano (02:30 del último domingo de marzo)
 * pasa a la misma hora del reloj ya adelantado (03:30); la que se repite en octubre, a la primera.
 */
final class ScheduleClock
{
    public function nextFor(ReportSchedule $schedule, CarbonImmutable $after): ?CarbonImmutable
    {
        return $this->next($schedule->frequency, $schedule->time, $schedule->weekday, $schedule->month_day, $schedule->run_at, $after);
    }

    public function next(
        ScheduleFrequency $frequency,
        string $time,
        ?int $weekday,
        ?int $monthDay,
        ?CarbonImmutable $runAt,
        CarbonImmutable $after,
    ): ?CarbonImmutable {
        if ($frequency === ScheduleFrequency::Once) {
            return $runAt !== null && $runAt->greaterThan($after) ? $runAt->utc() : null;
        }

        [$hour, $minute] = self::parseTime($time);
        $local = $after->setTimezone(LocalTime::timezone());

        if ($frequency === ScheduleFrequency::Weekly) {
            $day = max(1, min(7, (int) $weekday));
            $date = $local->startOfDay()->addDays(($day - $local->dayOfWeekIso + 7) % 7);
            $candidate = self::at($date, $hour, $minute);

            if ($candidate->lessThanOrEqualTo($after)) {
                $candidate = self::at($date->addDays(7), $hour, $minute);
            }

            return $candidate->utc();
        }

        $month = $local->startOfMonth();
        $candidate = self::at(self::dayOfMonth($month, (int) $monthDay), $hour, $minute);

        if ($candidate->lessThanOrEqualTo($after)) {
            $candidate = self::at(self::dayOfMonth($month->addMonthsNoOverflow(1), (int) $monthDay), $hour, $minute);
        }

        return $candidate->utc();
    }

    /**
     * Instante UTC de una fecha y hora locales (la de «una vez»).
     */
    public static function localInstant(string $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = self::parseTime($time);

        return self::at(CarbonImmutable::parse($date, LocalTime::timezone())->startOfDay(), $hour, $minute)->utc();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function parseTime(string $time): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $time.':0'));

        return [max(0, min(23, $hour)), max(0, min(59, $minute))];
    }

    private static function at(CarbonImmutable $day, int $hour, int $minute): CarbonImmutable
    {
        return $day->setTime($hour, $minute);
    }

    /** Día $day (1-28) del mes de $month, o el último si es 0. */
    private static function dayOfMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $last = $month->daysInMonth;

        return $month->setDay($day <= 0 ? $last : min($day, $last));
    }
}
