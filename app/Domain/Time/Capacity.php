<?php

namespace App\Domain\Time;

use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Capacidad de trabajo (SPEC §9): minutos de jornada de un usuario por fecha según su
 * WorkSchedule vigente o, si no tiene, el ajuste default_work_minutes (D-036).
 * Festivos y ausencias se descuentan a partir de la Fase 3.
 */
final class Capacity
{
    /**
     * Jornada por defecto, lunes primero (7 valores en minutos).
     *
     * @return list<int>
     */
    public static function defaultWeek(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) Setting::get('default_work_minutes', Setting::DEFAULTS['default_work_minutes']);
        $week = array_map(fn (mixed $minutes): int => max((int) $minutes, 0), array_values($configured));

        return array_pad(array_slice($week, 0, 7), 7, 0);
    }

    public function onDate(User $user, CarbonInterface $date): int
    {
        return $this->forRange($user, $date, $date)[$date->toDateString()] ?? 0;
    }

    /**
     * Minutos de capacidad por fecha (Y-m-d) entre $from y $to, ambos incluidos. Una consulta.
     *
     * @return array<string, int>
     */
    public function forRange(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDay = CarbonImmutable::parse($from->toDateString());
        $toDay = CarbonImmutable::parse($to->toDateString());

        $schedules = WorkSchedule::query()
            ->where('user_id', $user->id)
            ->where('valid_from', '<=', $toDay->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $fromDay->toDateString()))
            ->orderByDesc('valid_from')
            ->get();

        $default = self::defaultWeek();
        $capacity = [];

        foreach (CarbonPeriod::create($fromDay, $toDay) as $day) {
            $schedule = $schedules->first(fn (WorkSchedule $candidate): bool => $candidate->coversDate($day));
            $capacity[$day->toDateString()] = $schedule?->minutesFor($day) ?? $default[$day->dayOfWeekIso - 1];
        }

        return $capacity;
    }
}
