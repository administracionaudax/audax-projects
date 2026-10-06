<?php

namespace App\Domain\Forecast;

use App\Domain\Time\Capacity;
use App\Domain\Time\CapacityPlan;
use App\Domain\Workload\WorkloadPlanner;
use App\Enums\AllocationMode;
use App\Models\Allocation;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;

/**
 * Reparto de las asignaciones por días (docs/PLAN-CARGAS.md §6.2, D-282). «Día laborable» de una
 * persona = día con capacidad > 0 en Capacity (jornada − festivos − ausencias aprobadas); de un
 * departamento (hueco) = día en el que alguna de sus personas tiene capacidad (CapacityCalendar).
 *
 * | Modo     | Reparto |
 * |----------|---------|
 * | total    | A partes iguales entre los días laborables del rango; los minutos que sobran, a los primeros días; sin días laborables, todo al primero (y se marca «sin días») |
 * | per_day  | Los minutos cada día laborable del rango (aunque la jornada sea menor) |
 * | percent  | El % de la capacidad de ese día de la persona (las ausencias y los festivos lo bajan solos); de un hueco, el % de la jornada por defecto cada día laborable del departamento («0,5 personas») |
 * | monthly  | Cada mes natural del rango, sus minutos repartidos como total entre sus días laborables; un mes partido, a prorrata de días laborables. Sin fin: hasta el horizonte |
 *
 * Dos usos:
 * - **Plan completo** ($today = null): todo el rango de cada asignación, como se planificó. Es la
 *   línea base de un previsto (D-286) y el «plan» de la Planificación.
 * - **Carga desde hoy** ($today): solo cuenta de hoy en adelante. En un proyecto real, el modo total
 *   de una persona cuenta su **restante** = max(total − lo que esa persona ha imputado en ese
 *   proyecto dentro del rango, 0), repartido desde max(hoy, inicio) hasta el fin; si el fin ya pasó
 *   y queda restante, va a hoy y se marca «vencida» (como una tarea, D-051). El resto de modos y los
 *   previstos son de plan fijo: cuenta lo que cae de hoy en adelante.
 *
 * Más allá de un año desde hoy, la jornada semanal vigente en el tope, sin festivos ni ausencias
 * (la regla de D-051).
 */
final class AllocationPlanner
{
    public const int MAX_DAYS_AHEAD = WorkloadPlanner::MAX_DAYS_AHEAD;

    public function __construct(private readonly Capacity $capacity) {}

    /**
     * @param  array<array-key, Allocation>  $allocations
     * @param  int|array<int, int>  $horizonEnd  Día hasta el que llegan las mensuales sin fin (uno para
     *                                           todas o asignación → día; las que falten, el mayor).
     * @param  int|null  $today  null: plan completo; si no, carga desde ese día.
     * @param  array<int, int>  $logged  asignación → minutos imputados (restante del modo total).
     * @param  array{users?: list<int>, departments?: list<int>, from?: int, to?: int, members?: array<int, list<int>>}  $calendar
     *                                                                                                                              Personas, departamentos y días que el calendario de capacidad debe cubrir además de los de las asignaciones.
     */
    public function plan(array $allocations, int|array $horizonEnd, ?int $today = null, array $logged = [], array $calendar = []): AllocationPlan
    {
        $base = $today ?? CapacityPlan::day(LocalTime::todayString());
        $limit = $base + self::MAX_DAYS_AHEAD;

        $users = $calendar['users'] ?? [];
        $departments = $calendar['departments'] ?? [];
        $from = $calendar['from'] ?? PHP_INT_MAX;
        $to = $calendar['to'] ?? PHP_INT_MIN;

        if ($today !== null) {
            $from = min($from, $today);
            $to = max($to, $today);
        }

        foreach ($allocations as $allocation) {
            [$start, $end] = $this->range($allocation, $horizonEnd);

            if ($allocation->mode === AllocationMode::Monthly) {
                $start = self::monthStart($start);
                $end = self::monthEnd(max($end, $start));
            }

            $from = min($from, $start);
            $to = max($to, $end);

            if ($allocation->user_id !== null) {
                $users[] = $allocation->user_id;
            } elseif ($allocation->department_id !== null) {
                $departments[] = $allocation->department_id;
            }
        }

        if ($from > $to) {
            $from = $to = $base;
        }

        $capacity = CapacityCalendar::build(
            $this->capacity,
            array_values(array_unique($users)),
            array_values(array_unique($departments)),
            $from,
            $to,
            $limit,
            $calendar['members'] ?? null,
        );

        $plan = new AllocationPlan($capacity);

        foreach ($allocations as $allocation) {
            $plan->days[$allocation->id] = $this->distribute($allocation, $capacity, $horizonEnd, $today, $logged[$allocation->id] ?? 0, $plan);
        }

        return $plan;
    }

    /**
     * Minutos imputados que restan al modo total de cada asignación de una persona en un proyecto
     * real: los de esa persona en ese proyecto dentro del rango de la asignación. Una consulta.
     *
     * @param  iterable<Allocation>  $allocations
     * @return array<int, int> asignación → minutos
     */
    public static function logged(iterable $allocations): array
    {
        $ids = [];
        foreach ($allocations as $allocation) {
            if (self::countsRemaining($allocation)) {
                $ids[] = $allocation->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $logged = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('allocations')
                ->join('time_entries', function ($join): void {
                    $join->on('time_entries.project_id', '=', 'allocations.project_id')
                        ->on('time_entries.user_id', '=', 'allocations.user_id')
                        ->on('time_entries.date', '>=', 'allocations.start_date')
                        ->on('time_entries.date', '<=', 'allocations.end_date');
                })
                ->whereIn('allocations.id', $chunk)
                ->groupBy('allocations.id')
                ->selectRaw('allocations.id as id, SUM(time_entries.minutes) as minutes')
                ->get();

            foreach ($rows as $row) {
                $logged[(int) $row->id] = (int) $row->minutes;
            }
        }

        return $logged;
    }

    /** ¿Cuenta su restante (modo total de una persona en un proyecto real)? */
    public static function countsRemaining(Allocation $allocation): bool
    {
        return $allocation->mode === AllocationMode::Total
            && $allocation->user_id !== null
            && $allocation->project_id !== null
            && $allocation->end_date !== null;
    }

    /**
     * Días de inicio y fin de una asignación (las mensuales sin fin, hasta el horizonte).
     *
     * @param  int|array<int, int>  $horizonEnd
     * @return array{0: int, 1: int}
     */
    public function range(Allocation $allocation, int|array $horizonEnd): array
    {
        if (is_array($horizonEnd)) {
            $horizonEnd = $horizonEnd[$allocation->id] ?? ($horizonEnd === [] ? 0 : max($horizonEnd));
        }

        $start = CapacityPlan::day($allocation->start_date->toDateString());
        $end = $allocation->end_date === null ? $horizonEnd : CapacityPlan::day($allocation->end_date->toDateString());

        return [$start, $end];
    }

    /**
     * @param  int|array<int, int>  $horizonEnd
     * @return array<int, int> día → minutos
     */
    private function distribute(Allocation $allocation, CapacityCalendar $capacity, int|array $horizonEnd, ?int $today, int $logged, AllocationPlan $plan): array
    {
        [$start, $end] = $this->range($allocation, $horizonEnd);

        if ($end < $start) {
            return [];
        }

        // Los días laborables de la persona o del departamento, calculados una vez (rendimiento).
        $works = $capacity->workdays($allocation->user_id, $allocation->department_id);

        $minutes = (int) $allocation->minutes;

        // Restante de una persona en un proyecto real (solo en la carga desde hoy).
        if ($today !== null && self::countsRemaining($allocation)) {
            $remaining = max($minutes - $logged, 0);

            if ($remaining === 0) {
                return [];
            }

            if ($end < $today) {
                $plan->overdue[] = $allocation->id;

                return [$today => $remaining];
            }

            return $this->spread($remaining, self::workingDays($works, max($today, $start), $end), max($today, $start), $allocation, $plan);
        }

        $days = match ($allocation->mode) {
            AllocationMode::Total => $this->spread($minutes, self::workingDays($works, $start, $end), $start, $allocation, $plan, $today),
            AllocationMode::PerDay => self::perDay($minutes, self::workingDays($works, max($start, $today ?? $start), $end)),
            AllocationMode::Percent => $this->percent($allocation, $capacity, self::workingDays($works, max($start, $today ?? $start), $end)),
            AllocationMode::Monthly => $this->monthly($minutes, $works, $start, $end, $today),
        };

        if ($today === null) {
            return $days;
        }

        return array_filter($days, fn (int $day): bool => $day >= $today, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Reparto a partes iguales (el de WorkloadPlanner, D-051): los minutos que sobran, a los
     * primeros días; sin días laborables, todo al primer día del rango.
     *
     * @param  list<int>  $working
     * @return array<int, int>
     */
    private function spread(int $minutes, array $working, int $fallback, Allocation $allocation, AllocationPlan $plan, ?int $today = null): array
    {
        if ($minutes <= 0) {
            return [];
        }

        $count = count($working);

        if ($count === 0) {
            $plan->unscheduled[] = $allocation->id;

            return [$fallback => $minutes];
        }

        $share = intdiv($minutes, $count);
        $extra = $minutes % $count;
        $result = [];

        foreach ($working as $index => $day) {
            $value = $share + ($index < $extra ? 1 : 0);

            if ($value > 0 && ($today === null || $day >= $today)) {
                $result[$day] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $working
     * @return array<int, int>
     */
    private static function perDay(int $minutes, array $working): array
    {
        return $minutes <= 0 ? [] : array_fill_keys($working, $minutes);
    }

    /**
     * @param  list<int>  $working
     * @return array<int, int>
     */
    private function percent(Allocation $allocation, CapacityCalendar $capacity, array $working): array
    {
        $percent = (int) $allocation->percent;
        $userId = $allocation->user_id;
        $result = [];

        foreach ($working as $day) {
            $base = $userId !== null ? $capacity->forUser($userId, $day) : $capacity->defaultMinutes($day);
            $minutes = (int) round($base * $percent / 100);

            if ($minutes > 0) {
                $result[$day] = $minutes;
            }
        }

        return $result;
    }

    /**
     * Cada mes natural del rango, sus minutos (a prorrata de los días laborables si el mes está
     * partido) repartidos como total entre sus días laborables del rango.
     *
     * @param  list<int>  $works  días laborables del calendario, en orden
     * @return array<int, int>
     */
    private function monthly(int $minutes, array $works, int $start, int $end, ?int $today): array
    {
        $result = [];

        for ($month = self::monthStart($start); $month <= $end; $month = self::monthEnd($month) + 1) {
            $monthEnd = self::monthEnd($month);

            // Los meses enteros ya pasados no cuentan en la carga desde hoy.
            if ($today !== null && $monthEnd < $today) {
                continue;
            }

            $full = self::workingDays($works, $month, $monthEnd);
            $part = self::workingDays($works, max($month, $start), min($monthEnd, $end));

            if ($full === [] || $part === []) {
                continue;
            }

            $amount = count($part) === count($full)
                ? $minutes
                : (int) round($minutes * count($part) / count($full));
            $share = intdiv($amount, count($part));
            $extra = $amount % count($part);

            foreach ($part as $index => $day) {
                $value = $share + ($index < $extra ? 1 : 0);

                if ($value > 0) {
                    $result[$day] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Los días laborables entre $from y $to (ambos incluidos) de una lista ordenada, con una
     * búsqueda binaria del primero.
     *
     * @param  list<int>  $works
     * @return list<int>
     */
    private static function workingDays(array $works, int $from, int $to): array
    {
        $low = 0;
        $high = count($works);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($works[$middle] < $from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $days = [];
        for ($index = $low, $count = count($works); $index < $count && $works[$index] <= $to; $index++) {
            $days[] = $works[$index];
        }

        return $days;
    }

    /** Primer día del mes de $day. */
    public static function monthStart(int $day): int
    {
        return $day - ((int) gmdate('j', $day * 86400)) + 1;
    }

    /** Último día del mes de $day. */
    public static function monthEnd(int $day): int
    {
        $time = $day * 86400;

        return $day + ((int) gmdate('t', $time)) - ((int) gmdate('j', $time));
    }
}
