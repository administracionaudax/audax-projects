<?php

namespace App\Domain\Weeklies\Reminders;

use App\Domain\Weeklies\WeeklyCalendar;
use App\Models\WeeklyReminderRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Qué reglas de recordatorio tocan ahora (F-101, F-102 y F-107), port de findDueReminders de
 * WeeklySync (`_shared/reminderScheduling.js`) con instantes reales en lugar de minutos del día:
 *
 * - una regla (día ISO y «HH:MM» de Madrid) toca si su instante cae en (ahora − margen, ahora]. Con
 *   el comando cada 5 minutos y un margen de 10 (REMINDER_LOOKBACK_MINUTES del original), cada
 *   regla entra en dos pasadas: la clave del disparo (regla, semana, fecha y hora) evita el doble
 *   envío y un retraso del programador no la pierde,
 * - se miran hoy y ayer en Madrid, por si el margen cruza la medianoche,
 * - cambio de hora: una hora que no existe (el último domingo de marzo, de 02:00 a 02:59) se
 *   dispara al pasar a la hora de verano (02:30 → 03:30), en vez de perderse como en WeeklySync;
 *   una que se repite (el último domingo de octubre) se dispara una sola vez, en la segunda (ya en
 *   hora de invierno, la que elige PHP).
 *
 * Lógica pura: sin base de datos.
 */
final class WeeklyReminderSchedule
{
    public const int LOOKBACK_MINUTES = 10;

    /**
     * @param  iterable<WeeklyReminderRule>  $rules
     * @return list<array{rule: WeeklyReminderRule, date: string, due: CarbonImmutable}>
     */
    public static function due(iterable $rules, CarbonInterface $now, int $lookbackMinutes = self::LOOKBACK_MINUTES): array
    {
        $now = CarbonImmutable::instance($now);
        $from = $now->subMinutes(max(1, $lookbackMinutes));
        $dates = array_values(array_unique([
            $now->setTimezone(WeeklyCalendar::TIMEZONE)->toDateString(),
            $from->setTimezone(WeeklyCalendar::TIMEZONE)->toDateString(),
        ]));
        $due = [];

        foreach ($rules as $rule) {
            if (! $rule->enabled || self::minuteOfDay($rule->time) === null) {
                continue;
            }

            foreach ($dates as $date) {
                $at = self::instant($date, $rule->time);

                if ($at === null || $at->dayOfWeekIso !== $rule->day_of_week) {
                    continue;
                }

                if ($at->greaterThan($from) && $at->lessThanOrEqualTo($now)) {
                    $due[] = ['rule' => $rule, 'date' => $date, 'due' => $at->utc()];
                }
            }
        }

        return $due;
    }

    /** Clave del disparo de una regla, como buildAutomaticReminderTriggerKey: «rule:3:12:2026-10-09T16:00». */
    public static function triggerKey(WeeklyReminderRule $rule, int $cycleId, string $date): string
    {
        return "rule:{$rule->id}:{$cycleId}:{$date}T{$rule->time}";
    }

    /** Minuto del día de «HH:MM», o null si no es una hora válida (timeToMinuteOfDay). */
    public static function minuteOfDay(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    /**
     * El instante de una fecha y hora de Madrid. PHP lleva la hora que no existe al cambiar a la de
     * verano a la misma hora de reloj después del salto (02:30 → 03:30) y, en la que se repite,
     * toma la segunda (la de invierno).
     */
    private static function instant(string $date, string $time): ?CarbonImmutable
    {
        if (self::minuteOfDay($time) === null) {
            return null;
        }

        return CarbonImmutable::parse("{$date} {$time}:00", WeeklyCalendar::TIMEZONE);
    }
}
