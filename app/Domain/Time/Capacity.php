<?php

namespace App\Domain\Time;

use App\Models\Absence;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Capacidad de trabajo (SPEC §9): minutos de jornada de un usuario por fecha según su
 * WorkSchedule vigente (o el ajuste default_work_minutes, D-036), MENOS festivos (0 ese día) y
 * MENOS ausencias aprobadas (el día entero o sus partial_minutes; nunca por debajo de 0).
 * Todos los informes y la carga usan esta clase: la Fase 3 añadió festivos y ausencias aquí.
 *
 * Por tramos (PERF-05): la capacidad de cada persona es un CapacityPlan, los intervalos de días
 * con la semana de una versión de su horario (o la jornada por defecto), más los festivos y las
 * ausencias como días con su propia capacidad ($overrides). Las sumas se hacen con aritmética, sin
 * recorrer los días con Carbon. El resultado es el mismo que el detalle día a día de details().
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

        $userIds = array_values(array_unique(array_column($days, 'user_id')));

        $schedules = WorkSchedule::query()
            ->whereIn('user_id', $userIds)
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $default = self::defaultWeek();
        $holidays = self::holidays($from, $to);
        $absences = self::absences($userIds, $from, $to);
        /** @var array<int, list<array{from: int, to: int|null, week: list<int>}>> $versions horarios de cada persona, leídos una vez */
        $versions = [];

        return array_map(function (array $range) use ($schedules, $default, $holidays, $absences, &$versions): CapacityPlan {
            $versions[$range['user_id']] ??= array_values(array_map(fn (WorkSchedule $schedule): array => [
                'from' => CapacityPlan::day($schedule->valid_from->toDateString()),
                'to' => $schedule->valid_to === null ? null : CapacityPlan::day($schedule->valid_to->toDateString()),
                'week' => $schedule->weekMinutes(),
            ], ($schedules->get($range['user_id']) ?? new Collection)->all()));

            $from = CapacityPlan::day($range['from']);
            $to = CapacityPlan::day($range['to']);
            $plan = self::plan($versions[$range['user_id']], $default, $from, $to);
            $overrides = self::overrides($plan, $holidays, $absences[$range['user_id']] ?? [], $from, $to);

            return $overrides === [] ? $plan : new CapacityPlan($plan->segments, $overrides);
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
     * @param  array<int, int>  $overrides  Festivos y ausencias (día → minutos, overrides()).
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

    /**
     * Días con una capacidad distinta de la de su semana: los festivos (0) y las ausencias
     * aprobadas (el día entero, o sus partial_minutes sin bajar de 0), con las mismas reglas que el
     * detalle día a día de detailedDays().
     *
     * @param  array<string, string>  $holidays
     * @param  list<Absence>  $absences
     * @return array<int, int> Día → minutos.
     */
    private static function overrides(CapacityPlan $plan, array $holidays, array $absences, int $from, int $to): array
    {
        $overrides = [];

        foreach (array_keys($holidays) as $date) {
            $day = CapacityPlan::day($date);

            if ($day >= $from && $day <= $to) {
                $overrides[$day] = 0;
            }
        }

        foreach ($absences as $absence) {
            $first = max(CapacityPlan::day($absence->start_date->toDateString()), $from);
            $last = min(CapacityPlan::day($absence->end_date->toDateString()), $to);

            for ($day = $first; $day <= $last; $day++) {
                $overrides[$day] = $absence->partial_minutes === null
                    ? 0
                    : max(($overrides[$day] ?? $plan->base($day)) - $absence->partial_minutes, 0);
            }
        }

        ksort($overrides);

        return $overrides;
    }

    /**
     * Detalle por fecha de una persona: la jornada, lo que queda y por qué (festivo o ausencia).
     * Lo usa la vista Carga para pintar en gris los días sin capacidad con su motivo.
     *
     * @return array<string, array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}>
     */
    public function details(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDay = CarbonImmutable::parse($from->toDateString());
        $toDay = CarbonImmutable::parse($to->toDateString());

        $schedules = WorkSchedule::query()
            ->where('user_id', $user->id)
            ->where('valid_from', '<=', $toDay->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $fromDay->toDateString()))
            ->orderByDesc('valid_from')
            ->get();

        return self::detailedDays(
            $schedules,
            self::defaultWeek(),
            self::holidays($fromDay->toDateString(), $toDay->toDateString()),
            self::absences([$user->id], $fromDay->toDateString(), $toDay->toDateString())[$user->id] ?? [],
            $fromDay,
            $toDay,
        );
    }

    /**
     * Como details(), para varias personas y rangos a la vez, con UNA consulta de horarios, una de
     * festivos y una de ausencias en total: la vista Carga explica los días grises de todas sus
     * filas sin consultas por persona. Añadido por la vista Carga (Fase 3, W2).
     *
     * @param  list<array{user_id: int, from: CarbonInterface, to: CarbonInterface}>  $ranges
     * @return list<array<string, array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}>> En el mismo orden que $ranges.
     */
    public function detailsForRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $days = array_map(fn (array $range): array => [
            'user_id' => $range['user_id'],
            'from' => CarbonImmutable::parse($range['from']->toDateString()),
            'to' => CarbonImmutable::parse($range['to']->toDateString()),
        ], $ranges);

        $userIds = array_values(array_unique(array_column($days, 'user_id')));
        $from = min(array_map(fn (array $range): string => $range['from']->toDateString(), $days));
        $to = max(array_map(fn (array $range): string => $range['to']->toDateString(), $days));

        $schedules = WorkSchedule::query()
            ->whereIn('user_id', $userIds)
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $default = self::defaultWeek();
        $holidays = self::holidays($from, $to);
        $absences = self::absences($userIds, $from, $to);

        return array_map(
            fn (array $range): array => self::detailedDays($schedules->get($range['user_id']) ?? new Collection, $default, $holidays, $absences[$range['user_id']] ?? [], $range['from'], $range['to']),
            $days,
        );
    }

    /**
     * Jornada semanal (minutos, lunes primero) vigente en $date de cada persona, sin festivos ni
     * ausencias: la de su WorkSchedule de ese día o, si no tiene, la de por defecto. Una consulta.
     * La usa el reparto de la carga para contar los días laborables más allá del año que calcula
     * día a día (WorkloadPlanner, D-051).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<int>>
     */
    public function weeksOn(array $userIds, CarbonInterface $date): array
    {
        $day = $date->toDateString();
        $schedules = WorkSchedule::query()
            ->whereIn('user_id', $userIds)
            ->where('valid_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');
        $default = self::defaultWeek();

        $weeks = [];
        foreach ($userIds as $userId) {
            $weeks[$userId] = $schedules->get($userId)?->first()?->weekMinutes() ?? $default;
        }

        return $weeks;
    }

    /**
     * @return array<string, string> fecha → nombre del festivo
     */
    private static function holidays(string $from, string $to): array
    {
        /** @var array<string, string> */
        return Holiday::query()->whereBetween('date', [$from, $to])->get(['date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => $holiday->name])
            ->all();
    }

    /**
     * Ausencias aprobadas que se solapan con el rango, por persona.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<Absence>>
     */
    private static function absences(array $userIds, string $from, string $to): array
    {
        $byUser = [];
        foreach (Absence::query()->approved()->overlapping($from, $to)->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'type', 'start_date', 'end_date', 'partial_minutes', 'status']) as $absence) {
            $byUser[$absence->user_id][] = $absence;
        }

        return $byUser;
    }

    /**
     * Detalle por fecha con los horarios de UNA persona (ordenados del más reciente al más antiguo),
     * los festivos y sus ausencias aprobadas.
     *
     * Rendimiento (vista Carga, Fase 3): las fechas de los horarios y las ausencias se leen UNA vez
     * (cada acceso a un atributo con cast de fecha crea un Carbon) y los días se recorren como
     * fechas UTC (sin horario de verano), no con CarbonPeriod. El resultado es el mismo que
     * comparar día a día con coversDate(), minutesFor() y covers().
     *
     * @param  Collection<int, WorkSchedule>  $schedules
     * @param  list<int>  $default
     * @param  array<string, string>  $holidays
     * @param  list<Absence>  $absences
     * @return array<string, array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}>
     */
    private static function detailedDays(Collection $schedules, array $default, array $holidays, array $absences, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periods = [];
        foreach ($schedules as $schedule) {
            $periods[] = [
                'from' => $schedule->valid_from->toDateString(),
                'to' => $schedule->valid_to?->toDateString(),
                // Lunes primero, lo mismo que minutesFor() día a día.
                'week' => $schedule->weekMinutes(),
            ];
        }

        $leaves = array_map(fn (Absence $absence): array => [
            'from' => $absence->start_date->toDateString(),
            'to' => $absence->end_date->toDateString(),
            'type' => $absence->type->value,
            'partial_minutes' => $absence->partial_minutes,
        ], $absences);

        $capacity = [];
        $last = (int) strtotime($to->toDateString().' 00:00:00 UTC');

        for ($time = (int) strtotime($from->toDateString().' 00:00:00 UTC'); $time <= $last; $time += 86400) {
            $date = gmdate('Y-m-d', $time);
            $dayOfWeek = (int) gmdate('N', $time);
            $base = $default[$dayOfWeek - 1];

            foreach ($periods as $period) {
                if ($period['from'] <= $date && ($period['to'] === null || $period['to'] >= $date)) {
                    $base = $period['week'][$dayOfWeek - 1];

                    break;
                }
            }

            $minutes = $base;
            $holiday = $holidays[$date] ?? null;
            $absence = null;

            if ($holiday !== null) {
                $minutes = 0;
            }

            foreach ($leaves as $leave) {
                if ($leave['from'] > $date || $leave['to'] < $date) {
                    continue;
                }

                $absence ??= ['type' => $leave['type'], 'partial_minutes' => $leave['partial_minutes']];
                $minutes = $leave['partial_minutes'] === null ? 0 : max($minutes - $leave['partial_minutes'], 0);
            }

            $capacity[$date] = ['base' => $base, 'minutes' => $minutes, 'holiday' => $holiday, 'absence' => $absence];
        }

        return $capacity;
    }
}
