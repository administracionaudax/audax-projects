<?php

namespace App\Domain\DayPlan;

use App\Domain\Time\Capacity;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Las fechas del plan del día (docs/PLAN-CARGAS.md §4.1 y §4.4, D-252 y D-253), siempre en Madrid:
 *
 * - **Hora límite** (`day_plan_deadline`, 08:30): a esa hora sale el recordatorio a quien no ha
 *   escrito su plan y desde ella «Equipo hoy» marca «Sin plan» y las líneas añadidas después.
 * - **Planificar con antelación**: hoy y cualquier día hasta el domingo de la semana que viene.
 * - **Escribir** (añadir, cambiar el texto, borrar, ordenar): solo hoy y los días que vienen; el plan
 *   no se reescribe a posteriori.
 * - **Cerrar** (hecha, no hecha, pasar a otro día, imputar): también los `day_plan_editable_days`
 *   últimos días con jornada (1 por defecto: el último día con jornada). Los fines de semana, los
 *   festivos y las ausencias de día completo no cuentan, así que el lunes aún se cierra el viernes.
 */
final class DayPlanCalendar
{
    /** Días hacia atrás en los que se buscan días con jornada (y líneas pendientes). */
    public const int LOOKBACK_DAYS = 14;

    /** Máximo de días con jornada hacia atrás que se puede fijar en el ajuste. */
    public const int MAX_EDITABLE_DAYS = 5;

    public function __construct(private readonly Capacity $capacity) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(LocalTime::todayString());
    }

    /** Último día que se puede planificar: el domingo de la semana que viene. */
    public static function horizonEnd(?CarbonImmutable $today = null): CarbonImmutable
    {
        return ($today ?? self::today())->startOfWeek(CarbonInterface::MONDAY)->addDays(13);
    }

    /** «HH:MM» de la hora límite (siempre válida: si el ajuste está mal, 08:30). */
    public static function deadlineTime(): string
    {
        $value = Setting::get('day_plan_deadline', '08:30');

        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '08:30';
    }

    /** La hora límite de $date en Madrid, como instante. */
    public static function deadlineOn(CarbonInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return CarbonImmutable::parse($day.' '.self::deadlineTime(), LocalTime::timezone());
    }

    /** ¿Ha pasado ya la hora límite de $date? (los días pasados, siempre). */
    public static function pastDeadline(CarbonInterface|string $date, ?CarbonInterface $now = null): bool
    {
        return CarbonImmutable::instance($now ?? CarbonImmutable::now()) >= self::deadlineOn($date);
    }

    /** Días con jornada hacia atrás en los que se pueden cerrar líneas (ajuste, de 0 a 5). */
    public static function editableDays(): int
    {
        return max(0, min(self::MAX_EDITABLE_DAYS, (int) Setting::get('day_plan_editable_days', 1)));
    }

    /**
     * Primer día en el que $user aún puede cerrar líneas: el N-ésimo día con jornada anterior a hoy
     * (N = editableDays()); hoy si N es 0 o si no ha tenido jornada en las dos últimas semanas.
     */
    public function closableFrom(User $user, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= self::today();
        $wanted = self::editableDays();

        if ($wanted === 0) {
            return $today;
        }

        $days = $this->capacity->forRange($user, $today->subDays(self::LOOKBACK_DAYS), $today->subDay());
        krsort($days);
        $found = 0;

        foreach ($days as $date => $minutes) {
            if ($minutes > 0 && ++$found === $wanted) {
                return CarbonImmutable::parse((string) $date);
            }
        }

        return $found > 0 ? CarbonImmutable::parse((string) array_key_last(array_filter($days, fn (int $minutes): bool => $minutes > 0))) : $today;
    }

    /** ¿Se pueden añadir, editar, borrar u ordenar líneas de $date? Hoy y hasta el horizonte. */
    public static function writable(CarbonInterface|string $date, ?CarbonImmutable $today = null): bool
    {
        $today ??= self::today();
        $day = self::date($date);

        return $day >= $today->toDateString() && $day <= self::horizonEnd($today)->toDateString();
    }

    /** ¿Puede $user cerrar (hecha, no hecha, pasar, imputar) líneas de $date? */
    public function closable(User $user, CarbonInterface|string $date, ?CarbonImmutable $today = null): bool
    {
        $today ??= self::today();
        $day = self::date($date);

        if ($day > self::horizonEnd($today)->toDateString()) {
            return false;
        }

        return $day >= $today->toDateString() || $day >= $this->closableFrom($user, $today)->toDateString();
    }

    private static function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : $date;
    }
}
