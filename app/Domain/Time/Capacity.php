<?php

namespace App\Domain\Time;

use App\Models\Absence;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Capacidad de trabajo (SPEC §9): minutos de jornada de un usuario por fecha según su
 * WorkSchedule vigente (o el ajuste default_work_minutes, D-036), MENOS festivos (0 ese día) y
 * MENOS ausencias aprobadas (el día entero o sus partial_minutes; nunca por debajo de 0).
 * Todos los informes y la carga usan esta clase: la Fase 3 añadió festivos y ausencias aquí.
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

        return $this->forRanges([['user_id' => $user->id, 'from' => $fromDay, 'to' => $toDay]])[0];
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
        if ($ranges === []) {
            return [];
        }

        $days = array_map(fn (array $range): array => [
            'user_id' => $range['user_id'],
            'from' => CarbonImmutable::parse($range['from']->toDateString()),
            'to' => CarbonImmutable::parse($range['to']->toDateString()),
        ], $ranges);

        $from = min(array_map(fn (array $range): string => $range['from']->toDateString(), $days));
        $to = max(array_map(fn (array $range): string => $range['to']->toDateString(), $days));

        $schedules = WorkSchedule::query()
            ->whereIn('user_id', array_values(array_unique(array_column($days, 'user_id'))))
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $default = self::defaultWeek();
        $holidays = self::holidays($from, $to);
        $absences = self::absences(array_values(array_unique(array_column($days, 'user_id'))), $from, $to);

        return array_map(
            fn (array $range): array => array_map(
                fn (array $day): int => $day['minutes'],
                self::detailedDays($schedules->get($range['user_id']) ?? new Collection, $default, $holidays, $absences[$range['user_id']] ?? [], $range['from'], $range['to']),
            ),
            $days,
        );
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
     * @param  Collection<int, WorkSchedule>  $schedules
     * @param  list<int>  $default
     * @param  array<string, string>  $holidays
     * @param  list<Absence>  $absences
     * @return array<string, array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}>
     */
    private static function detailedDays(Collection $schedules, array $default, array $holidays, array $absences, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $capacity = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $schedule = $schedules->first(fn (WorkSchedule $candidate): bool => $candidate->coversDate($day));
            $base = $schedule?->minutesFor($day) ?? $default[$day->dayOfWeekIso - 1];
            $minutes = $base;
            $holiday = $holidays[$date] ?? null;
            $absence = null;

            if ($holiday !== null) {
                $minutes = 0;
            }

            foreach ($absences as $candidate) {
                if (! $candidate->covers($date)) {
                    continue;
                }

                $absence ??= ['type' => $candidate->type->value, 'partial_minutes' => $candidate->partial_minutes];
                $minutes = $candidate->partial_minutes === null ? 0 : max($minutes - $candidate->partial_minutes, 0);
            }

            $capacity[$date] = ['base' => $base, 'minutes' => $minutes, 'holiday' => $holiday, 'absence' => $absence];
        }

        return $capacity;
    }
}
