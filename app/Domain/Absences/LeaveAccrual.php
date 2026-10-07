<?php

namespace App\Domain\Absences;

use App\Domain\Time\Capacity;
use App\Enums\LeaveUnit;
use App\Models\EmploymentProfile;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La asignación anual de un tipo con saldo para una persona (Fase 11, R3; W-060 y W-064; L-17;
 * D-362):
 *
 * - **Proporcional al alta y a la baja**: se cuentan los meses del año en los que está de alta y
 *   cada fracción de mes cuenta como un mes entero (convenio de publicidad, art. 23; lo más
 *   favorable mientras la asesoría no diga otra cosa). 22 días × 6 meses / 12 = 11 días.
 * - **Proporcional al tiempo parcial** cuando trabaja **menos días** a la semana (art. 12.4.d ET:
 *   los mismos días naturales de vacaciones; en laborables, en proporción a los días que trabaja):
 *   con 3 días a la semana, 22 × 3/5. Si trabaja todos los días con menos horas, los mismos 22 días.
 *   Se mira la jornada vigente al empezar cada mes (sin la de verano).
 * - En los tipos **por horas con la asignación en días** (fuerza mayor: 4 días al año), las horas de
 *   esos días con su jornada media de cada mes (4 días de 6 h = 24 h).
 * - Se redondea **hacia arriba**: al medio día en los de días y al minuto en los de horas.
 */
final class LeaveAccrual
{
    /** Días laborables de una semana completa. */
    public const int FULL_WEEK_DAYS = 5;

    /**
     * @param  Collection<int, WorkSchedule>  $schedules  Los de la persona (cualquier orden).
     */
    public static function amount(LeaveType $type, int $year, ?EmploymentProfile $profile, Collection $schedules): int
    {
        if (! $type->hasAllowance()) {
            return 0;
        }

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        if ($profile?->hire_date !== null) {
            $from = max($from, $profile->hire_date->toDateString());
        }
        if ($profile?->termination_date !== null) {
            $to = min($to, $profile->termination_date->toDateString());
        }
        if ($from > $to) {
            return 0;
        }

        $sorted = $schedules->sortByDesc(fn (WorkSchedule $schedule): string => $schedule->valid_from->toDateString())->values();
        $default = Capacity::defaultWeek();
        $sum = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = CarbonImmutable::create($year, $month, 1);
            $first = max($monthStart->toDateString(), $from);
            $last = min($monthStart->endOfMonth()->toDateString(), $to);

            if ($first > $last) {
                continue;
            }

            $schedule = $sorted->first(fn (WorkSchedule $candidate): bool => $candidate->coversDate(CarbonImmutable::parse($first)));
            $week = $schedule?->weekMinutes() ?? $default;
            $workingDays = count(array_filter($week, fn (int $minutes): bool => $minutes > 0));

            $sum += match (true) {
                $type->unit === LeaveUnit::Hours && $type->allowance_in_days => $workingDays === 0 ? 0 : array_sum($week) / $workingDays / LeaveCatalog::DAY,
                $type->unit === LeaveUnit::WorkingDays => min(1.0, $workingDays / self::FULL_WEEK_DAYS),
                default => 1.0,
            };
        }

        $raw = $type->annual_allowance * $sum / 12;

        if ($type->unit->isDays()) {
            $half = intdiv(LeaveCatalog::DAY, 2);

            return (int) (ceil(round($raw, 4) / $half) * $half);
        }

        return (int) ceil(round($raw, 4));
    }
}
