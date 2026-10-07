<?php

namespace App\Domain\People;

use App\Domain\Time\Capacity;
use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Enums\WorkdayIncident;
use App\Enums\WorkMode;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\EmploymentProfile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * El diario del registro de jornada (PLAN-FASE-11 §7; D-337 y D-338): para cada persona y día,
 * las jornadas con sus fichajes efectivos, lo trabajado, las pausas, la jornada teórica, la
 * diferencia, el exceso, los modos, las incidencias y el estado. Se calcula siempre a partir de la
 * cadena (nunca se guarda un total que pueda divergir); el cierre mensual de R2 congelará lo que
 * devuelva esto.
 *
 * - **Trabajado**: la suma de los tramos de trabajo efectivos, en segundos reales (UTC: el cambio
 *   de hora cuenta lo que de verdad pasó) y redondeado al minuto. La pausa de la comida no cuenta
 *   (D-334). Un tramo abierto solo cuenta hasta ahora si la jornada sigue en curso; si se quedó sin
 *   salida, no cuenta (lo que pasó después no se sabe: la persona propone la salida).
 * - **Teórica**: Capacity (la jornada vigente o la de verano, menos festivos y ausencias
 *   aprobadas), y 0 fuera del periodo de alta o antes del inicio del registro.
 * - **Diferencia** = trabajado − teórica; **exceso** = la parte positiva. R1 registra todo el
 *   exceso; R2 decide qué es hora extra y su destino (P6).
 * - **Incidencias**: WorkdayIncident. Avisan, no bloquean ni corrigen.
 *
 * @phpstan-type DayEvent array{id: int, kind: string, at: string, work_mode: string|null, pause_type: string|null, source: string, correction_id: int|null}
 * @phpstan-type DaySegment array{kind: string, from: string, to: string|null, work_mode: string|null}
 * @phpstan-type DayWorkday array{clock_in: string, clock_out: string|null, open: bool, stale: bool, segments: list<DaySegment>}
 * @phpstan-type Day array{date: string, weekday: int, expected_minutes: int, capacity_minutes: int, base_minutes: int, holiday: string|null, absence: array{type: string|null, partial_minutes: int|null}|null, employed: bool, registered: bool, schedule: array{start_from: string|null, start_to: string|null, expected_pause_minutes: int}|null, workdays: list<DayWorkday>, events: list<DayEvent>, worked_minutes: int, pause_minutes: int, difference_minutes: int|null, excess_minutes: int, modes: list<string>, incidents: list<string>, in_progress: bool, status: string, pending_corrections: int, disputed_corrections: int}
 */
final class WorkdayCalculator
{
    /** «Menos horas»: 30 minutos o más por debajo de la teórica (la flexibilidad del convenio). */
    public const int SHORT_DAY_TOLERANCE = 30;

    /** Descanso mínimo entre jornadas (art. 34.3 ET). */
    public const int MIN_REST_MINUTES = 12 * 60;

    /** Trabajo seguido sin pausa a partir del que hace falta un descanso (art. 34.4 ET). */
    public const int MAX_STRETCH_MINUTES = 6 * 60;

    /** Horas ordinarias diarias como máximo (art. 34.3 ET). */
    public const int MAX_DAY_MINUTES = 9 * 60;

    public function __construct(private readonly Capacity $capacity) {}

    /**
     * El diario de una persona entre dos días (AAAA-MM-DD, ambos incluidos).
     *
     * @return array<string, Day>
     */
    public function forUser(User $user, string $from, string $to, ?User $viewer = null, ?CarbonImmutable $now = null): array
    {
        return $this->forUsers([$user], $from, $to, $viewer, $now)[$user->id] ?? [];
    }

    /**
     * El diario de varias personas a la vez, con una consulta de fichajes, una de correcciones, una
     * de horarios y las de Capacity en total («Jornada del equipo»).
     *
     * @param  array<int, User>  $users
     * @return array<int, array<string, Day>>
     */
    public function forUsers(array $users, string $from, string $to, ?User $viewer = null, ?CarbonImmutable $now = null): array
    {
        $users = array_values($users);

        if ($users === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $today = LocalTime::dateOf($now);
        $ids = array_map(fn (User $user): int => $user->id, $users);
        $zone = LocalTime::timezone();

        $details = $this->capacity->detailsForRanges(array_map(fn (User $user): array => [
            'user_id' => $user->id,
            'from' => CarbonImmutable::parse($from),
            'to' => CarbonImmutable::parse($to),
        ], $users));

        $profiles = EmploymentProfile::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $schedules = WorkSchedule::query()
            ->whereIn('user_id', $ids)
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $events = ClockEvent::query()
            ->whereIn('user_id', $ids)
            ->whereIn('kind', ClockEventKind::punchValues())
            ->whereNotExists(fn (QueryBuilder $voids) => $voids->from('clock_events as voids')->whereColumn('voids.voided_event_id', 'clock_events.id'))
            ->whereBetween('occurred_at', [
                CarbonImmutable::parse($from, $zone)->startOfDay()->subDays(3)->utc(),
                CarbonImmutable::parse($to, $zone)->endOfDay()->addDays(2)->utc(),
            ])
            ->orderBy('occurred_at')
            ->orderBy('seq')
            ->get()
            ->groupBy('user_id');

        $corrections = ClockCorrection::query()
            ->whereIn('user_id', $ids)
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', [CorrectionStatus::Pending->value, CorrectionStatus::Disputed->value])
            ->get(['id', 'user_id', 'date', 'status'])
            ->groupBy('user_id');

        $start = RegisterStart::date();
        $result = [];

        foreach ($users as $index => $user) {
            /** @var list<ClockEvent> $own */
            $own = array_values(($events->get($user->id) ?? collect())->all());
            $workdays = RegisterTimeline::build($own);
            $byDate = [];
            foreach ($workdays as $workday) {
                $byDate[$workday->date][] = $workday;
            }

            $pending = [];
            $disputed = [];
            foreach ($corrections->get($user->id) ?? [] as $correction) {
                $day = $correction->date->toDateString();
                if ($correction->status === CorrectionStatus::Pending) {
                    $pending[$day] = ($pending[$day] ?? 0) + 1;
                } else {
                    $disputed[$day] = ($disputed[$day] ?? 0) + 1;
                }
            }

            /** @var EmploymentProfile|null $profile */
            $profile = $profiles->get($user->id);
            $seesAbsenceType = $viewer === null || $viewer->canSeeAbsencesOf($user);
            $userSchedules = $schedules->get($user->id) ?? collect();
            $days = [];

            foreach ($details[$index] as $date => $detail) {
                $days[$date] = $this->day(
                    $date,
                    $detail,
                    $byDate[$date] ?? [],
                    self::previousClockOut($workdays, $date),
                    $profile,
                    $userSchedules->first(fn (WorkSchedule $schedule): bool => $schedule->coversDate(CarbonImmutable::parse($date))),
                    $start,
                    $today,
                    $now,
                    $seesAbsenceType,
                    $pending[$date] ?? 0,
                    $disputed[$date] ?? 0,
                );
            }

            $result[$user->id] = $days;
        }

        return $result;
    }

    /**
     * Totales de un diario: trabajado, teórica y diferencia hasta hoy (los días futuros y la teórica de
     * hoy mientras no se cierra no cuentan),
     * exceso, días con incidencia y pendientes.
     *
     * @param  array<string, Day>  $days
     * @return array{worked_minutes: int, expected_minutes: int, difference_minutes: int, excess_minutes: int, incident_days: int, pending_days: int, days_worked: int}
     */
    public static function totals(array $days): array
    {
        $totals = ['worked_minutes' => 0, 'expected_minutes' => 0, 'difference_minutes' => 0, 'excess_minutes' => 0, 'incident_days' => 0, 'pending_days' => 0, 'days_worked' => 0];

        foreach ($days as $day) {
            if ($day['status'] === 'future') {
                continue;
            }

            $totals['worked_minutes'] += $day['worked_minutes'];
            // Hoy sin cerrar (sin diferencia todavía) no suma su teórica: no hay deuda a media mañana.
            $totals['expected_minutes'] += $day['difference_minutes'] === null ? 0 : $day['expected_minutes'];
            $totals['excess_minutes'] += $day['excess_minutes'];
            $totals['incident_days'] += $day['incidents'] === [] ? 0 : 1;
            $totals['pending_days'] += $day['pending_corrections'] > 0 ? 1 : 0;
            $totals['days_worked'] += $day['workdays'] === [] ? 0 : 1;
        }

        $totals['difference_minutes'] = $totals['worked_minutes'] - $totals['expected_minutes'];

        return $totals;
    }

    /**
     * @param  array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}  $detail
     * @param  list<Workday>  $workdays
     * @return Day
     */
    private function day(
        string $date,
        array $detail,
        array $workdays,
        ?CarbonImmutable $previousClockOut,
        ?EmploymentProfile $profile,
        ?WorkSchedule $schedule,
        ?string $start,
        string $today,
        CarbonImmutable $now,
        bool $seesAbsenceType,
        int $pending,
        int $disputed,
    ): array {
        $employed = $profile === null || $profile->employedOn($date);
        $registered = $start !== null && $date >= $start;
        $expected = $employed && $registered ? $detail['minutes'] : 0;
        $past = $date < $today;
        $future = $date > $today;

        $workedSeconds = 0;
        $pauseSeconds = 0;
        $longest = 0;
        $missingOut = false;
        $pauseOpen = false;
        $inProgress = false;
        $modes = [];
        $serializedWorkdays = [];
        $events = [];

        foreach ($workdays as $workday) {
            $stale = ClockState::isStale($workday, $now);
            $running = $workday->isOpen() && ! $stale;
            $until = $running ? $now : null;

            $workedSeconds += $workday->workedSeconds($until);
            $pauseSeconds += $workday->pauseSeconds($until);
            $longest = max($longest, $workday->longestStretchSeconds($until));
            $missingOut = $missingOut || ($workday->isOpen() && ($stale || $past));
            $pauseOpen = $pauseOpen || $workday->pauseOpenAtClockOut;
            $inProgress = $inProgress || $running;

            foreach ($workday->modes() as $mode) {
                if (! in_array($mode->value, $modes, true)) {
                    $modes[] = $mode->value;
                }
            }

            $serializedWorkdays[] = [
                'clock_in' => self::iso($workday->clockInAt),
                'clock_out' => self::iso($workday->clockOutAt()),
                'open' => $workday->isOpen(),
                'stale' => $stale,
                'segments' => array_map(fn (array $segment): array => [
                    'kind' => $segment['kind'],
                    'from' => self::iso($segment['from']),
                    'to' => $segment['to'] === null ? null : self::iso($segment['to']),
                    'work_mode' => $segment['mode']?->value,
                ], $workday->segments),
            ];

            foreach ($workday->events as $event) {
                $events[] = self::event($event);
            }
        }

        $worked = (int) round($workedSeconds / 60);
        $pause = (int) round($pauseSeconds / 60);
        // Hoy, mientras la jornada sigue en curso o aún no se ha fichado, no hay diferencia: saldría
        // negativa toda la mañana. Se calcula al cerrar la jornada (o mañana).
        $pendingToday = $date === $today && ($inProgress || $workdays === []);
        $difference = $future || $pendingToday ? null : $worked - $expected;
        $incidents = [];

        if ($employed && $registered && ! $future) {
            if ($missingOut) {
                $incidents[] = WorkdayIncident::MissingClockOut;
            }

            if ($past && $workdays === [] && $expected > 0) {
                $incidents[] = WorkdayIncident::NoRecords;
            }

            if ($pauseOpen) {
                $incidents[] = WorkdayIncident::PauseOpen;
            }

            if ($workdays !== [] && $detail['absence'] !== null && $detail['absence']['partial_minutes'] === null) {
                $incidents[] = WorkdayIncident::DuringAbsence;
            }

            if ($past && $workdays !== [] && ! $missingOut && $expected > 0 && $worked <= $expected - self::SHORT_DAY_TOLERANCE) {
                $incidents[] = WorkdayIncident::ShortDay;
            }

            if ($workdays !== [] && $previousClockOut !== null
                && ($workdays[0]->clockInAt->getTimestamp() - $previousClockOut->getTimestamp()) < self::MIN_REST_MINUTES * 60) {
                $incidents[] = WorkdayIncident::ShortRest;
            }

            if ($longest > self::MAX_STRETCH_MINUTES * 60) {
                $incidents[] = WorkdayIncident::LongStretch;
            }

            if ($worked > self::MAX_DAY_MINUTES) {
                $incidents[] = WorkdayIncident::OverNineHours;
            }
        }

        $absence = $detail['absence'] === null ? null : [
            'type' => $seesAbsenceType ? $detail['absence']['type'] : null,
            'partial_minutes' => $detail['absence']['partial_minutes'],
        ];

        return [
            'date' => $date,
            'weekday' => CarbonImmutable::parse($date)->dayOfWeekIso,
            'expected_minutes' => $expected,
            'capacity_minutes' => $detail['minutes'],
            'base_minutes' => $detail['base'],
            'holiday' => $detail['holiday'],
            'absence' => $absence,
            'employed' => $employed,
            'registered' => $registered,
            'schedule' => $schedule === null ? null : [
                'start_from' => self::clock($schedule->start_time_from),
                'start_to' => self::clock($schedule->start_time_to),
                'expected_pause_minutes' => $schedule->expectedPauseOn($date),
            ],
            'workdays' => $serializedWorkdays,
            'events' => $events,
            'worked_minutes' => $worked,
            'pause_minutes' => $pause,
            'difference_minutes' => $difference,
            'excess_minutes' => $difference !== null && ! $inProgress ? max($difference, 0) : 0,
            'modes' => $modes,
            'incidents' => array_map(fn (WorkdayIncident $incident): string => $incident->value, $incidents),
            'in_progress' => $inProgress,
            'status' => self::status($incidents, $pending, $disputed, $inProgress, $future, $workdays !== [], $expected, $date === $today),
            'pending_corrections' => $pending,
            'disputed_corrections' => $disputed,
        ];
    }

    /**
     * Estado del día, en este orden: corrección pendiente, en discrepancia, incidencia que pide
     * hacer algo, aviso de un límite legal, en curso, futuro, sin jornada (descanso, festivo o
     * ausencia), hoy aún sin fichar y correcto.
     *
     * @param  list<WorkdayIncident>  $incidents
     */
    private static function status(array $incidents, int $pending, int $disputed, bool $inProgress, bool $future, bool $worked, int $expected, bool $isToday): string
    {
        return match (true) {
            $pending > 0 => 'pending',
            $disputed > 0 => 'disputed',
            array_filter($incidents, fn (WorkdayIncident $incident): bool => $incident->needsAction()) !== [] => 'incident',
            $incidents !== [] => 'warning',
            $inProgress => 'in_progress',
            $future => 'future',
            ! $worked && $expected === 0 => 'off',
            ! $worked && $isToday => 'today',
            default => 'ok',
        };
    }

    /**
     * Salida de la última jornada que empezó antes de $date (para el descanso entre jornadas).
     *
     * @param  list<Workday>  $workdays
     */
    private static function previousClockOut(array $workdays, string $date): ?CarbonImmutable
    {
        $previous = null;

        foreach ($workdays as $workday) {
            if ($workday->date >= $date) {
                break;
            }

            $previous = $workday->clockOutAt() ?? $previous;
        }

        return $previous;
    }

    /**
     * @return DayEvent
     */
    public static function event(ClockEvent $event): array
    {
        return [
            'id' => $event->id,
            'kind' => $event->kind->value,
            'at' => self::iso($event->occurred_at),
            'work_mode' => $event->work_mode?->value,
            'pause_type' => $event->pause_type?->value,
            'source' => $event->source->value,
            'correction_id' => $event->correction_id,
        ];
    }

    public static function iso(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->toIso8601ZuluString();
    }

    /** "09:00:00" → "09:00". */
    private static function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * Modos legibles (para los textos del servidor).
     *
     * @param  list<string>  $modes
     */
    public static function modesLabel(array $modes): string
    {
        return implode(', ', array_map(fn (string $mode): string => WorkMode::from($mode)->label(), $modes));
    }
}
