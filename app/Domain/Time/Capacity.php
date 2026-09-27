<?php

namespace App\Domain\Time;

use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Capacidad de trabajo (SPEC §9): minutos de jornada de un usuario por fecha según su
 * WorkSchedule vigente o, si no tiene, el ajuste default_work_minutes (D-036).
 * Festivos y ausencias se descuentan a partir de la Fase 3 (CapacityPlan::$overrides).
 *
 * Por tramos (PERF-05): la capacidad de cada persona es un CapacityPlan, los intervalos de días
 * con la semana de una versión de su horario (o la jornada por defecto), y las sumas se hacen con
 * aritmética, sin recorrer los días con Carbon. El resultado es el mismo que comparar día a día
 * (con coversDate() y minutesFor() sobre los horarios del más reciente al más antiguo).
 *
 * @phpstan-import-type Segment from CapacityPlan
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
        return $this->forRanges([['user_id' => $user->id, 'from' => $from, 'to' => $to]])[0];
    }

    /**
     * Como forRange, para varias personas y rangos a la vez, con UNA consulta en total (p. ej. las
     * semanas pendientes de /horas/aprobaciones). Añadido por el área Horas (1.6).
     *
     * @param  list<array{user_id: int, from: CarbonInterface, to: CarbonInterface}>  $ranges
     * @return list<array<string, int>> Minutos por fecha (Y-m-d) de cada rango, en el mismo orden.
     */
    public function forRanges(array $ranges): array
    {
        return array_map(fn (CapacityPlan $plan): array => $plan->byDate(), $this->plansForRanges($ranges));
    }

    /**
     * La capacidad de varias personas y rangos como tramos (CapacityPlan), con UNA consulta de
     * horarios en total: para sumar sin recorrer los días (informes, PERF-05).
     *
     * @param  list<array{user_id: int, from: CarbonInterface, to: CarbonInterface}>  $ranges
     * @return list<CapacityPlan> En el mismo orden que $ranges.
     */
    public function plansForRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $days = array_map(fn (array $range): array => [
            'user_id' => $range['user_id'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
        ], $ranges);

        $from = min(array_column($days, 'from'));
        $to = max(array_column($days, 'to'));

        $schedules = WorkSchedule::query()
            ->whereIn('user_id', array_values(array_unique(array_column($days, 'user_id'))))
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $default = self::defaultWeek();
        /** @var array<int, list<array{from: int, to: int|null, week: list<int>}>> $versions horarios de cada persona, leídos una vez */
        $versions = [];

        return array_map(function (array $range) use ($schedules, $default, &$versions): CapacityPlan {
            $versions[$range['user_id']] ??= array_values(array_map(fn (WorkSchedule $schedule): array => [
                'from' => CapacityPlan::day($schedule->valid_from->toDateString()),
                'to' => $schedule->valid_to === null ? null : CapacityPlan::day($schedule->valid_to->toDateString()),
                'week' => $schedule->weekMinutes(),
            ], ($schedules->get($range['user_id']) ?? new Collection)->all()));

            return self::plan($versions[$range['user_id']], $default, CapacityPlan::day($range['from']), CapacityPlan::day($range['to']));
        }, $days);
    }

    /**
     * Tramos de una persona entre dos días: en cada intervalo entre dos cambios de versión, la del
     * horario más reciente (por valid_from) que lo cubre o, si ninguno, la jornada por defecto;
     * los intervalos seguidos con la misma semana se juntan. Pública para comprobar la equivalencia
     * con el cálculo día a día (tests).
     *
     * @param  list<array{from: int, to: int|null, week: list<int>}>  $versions  Del más reciente al más antiguo.
     * @param  list<int>  $default
     * @param  array<int, int>  $overrides  Festivos y ausencias (día → minutos), a partir de la Fase 3.
     */
    public static function plan(array $versions, array $default, int $from, int $to, array $overrides = []): CapacityPlan
    {
        if ($to < $from) {
            return new CapacityPlan([]);
        }

        // Los días en los que puede cambiar la versión vigente: donde empieza o acaba alguna.
        $cuts = [$from => true];
        foreach ($versions as $version) {
            foreach ([$version['from'], $version['to'] === null ? null : $version['to'] + 1] as $cut) {
                if ($cut !== null && $cut > $from && $cut <= $to) {
                    $cuts[$cut] = true;
                }
            }
        }
        $cuts = array_keys($cuts);
        sort($cuts);

        /** @var list<Segment> $segments */
        $segments = [];
        foreach ($cuts as $index => $start) {
            $end = ($cuts[$index + 1] ?? $to + 1) - 1;
            $week = $default;

            foreach ($versions as $version) {
                if ($version['from'] <= $start && ($version['to'] === null || $version['to'] >= $start)) {
                    $week = $version['week'];

                    break;
                }
            }

            $last = array_key_last($segments);
            if ($last !== null && $segments[$last]['week'] === $week) {
                $segments[$last]['to'] = $end;
            } else {
                $segments[] = ['from' => $start, 'to' => $end, 'week' => $week];
            }
        }

        $overrides = array_filter($overrides, fn (int $day): bool => $day >= $from && $day <= $to, ARRAY_FILTER_USE_KEY);

        return new CapacityPlan($segments, $overrides);
    }
}
