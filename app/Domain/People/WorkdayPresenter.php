<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Lo que pintan las pantallas del registro (PLAN-FASE-11 §6; D-341): el diario del mes de una
 * persona con sus totales, el detalle de un día con su **historial** (fichajes originales,
 * anulados y añadidos, con quién, cuándo y por qué: W-020 y W-022) y las correcciones con lo que
 * puede hacer quien mira.
 *
 * @phpstan-import-type Day from WorkdayCalculator
 */
final class WorkdayPresenter
{
    public function __construct(
        private readonly WorkdayCalculator $calculator,
        private readonly ClockCorrectionService $corrections,
    ) {}

    /**
     * El mes de una persona (AAAA-MM) con sus totales, la semana en curso y el día abierto.
     *
     * @return array<string, mixed>
     */
    public function month(User $subject, User $viewer, CarbonImmutable $month, ?string $day): array
    {
        $now = CarbonImmutable::now();
        $today = LocalTime::dateOf($now);
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $weekStart = CarbonImmutable::parse($today)->startOfWeek()->toDateString();
        $weekEnd = CarbonImmutable::parse($today)->endOfWeek()->toDateString();

        $days = $this->calculator->forUser($subject, min($from, $weekStart), max($to, $weekEnd), $viewer, $now);
        $monthDays = array_values(array_filter($days, fn (array $item): bool => $item['date'] >= $from && $item['date'] <= $to));
        $weekDays = array_filter($days, fn (array $item): bool => $item['date'] >= $weekStart && $item['date'] <= $weekEnd);

        return [
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'avatar' => $subject->avatar_url, 'is_me' => $subject->id === $viewer->id],
            'month' => $month->format('Y-m'),
            'today' => $today,
            'days' => $monthDays,
            'totals' => WorkdayCalculator::totals(array_column($monthDays, null, 'date')),
            'week' => WorkdayCalculator::totals($weekDays),
            'today_day' => $days[$today] ?? null,
            'detail' => $day !== null && $day <= $today ? $this->detail($subject, $viewer, $day) : null,
            'awaiting_me' => $this->awaitingSubject($subject, $viewer),
            'can' => [
                'propose' => PeopleAccess::proposesFor($viewer, $subject),
            ],
        ];
    }

    /**
     * Un día: el diario, el historial completo y sus correcciones.
     *
     * @return array<string, mixed>
     */
    public function detail(User $subject, User $viewer, string $date): array
    {
        $now = CarbonImmutable::now();
        $day = $this->calculator->forUser($subject, $date, $date, $viewer, $now)[$date];
        $corrections = ClockCorrection::query()
            ->where('user_id', $subject->id)
            ->where('date', $date)
            ->with(['proposer:id,name', 'decider:id,name'])
            ->orderBy('id')
            ->get();

        $effectiveIds = array_column($day['events'], 'id');
        $correctionIds = $corrections->modelKeys();
        $zone = LocalTime::timezone();
        $start = CarbonImmutable::parse($date, $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($date, $zone)->endOfDay()->addDay()->utc();

        $rows = ClockEvent::query()
            ->where('user_id', $subject->id)
            ->where(fn ($query) => $query
                ->whereIn('id', $effectiveIds ?: [0])
                ->orWhereIn('correction_id', $correctionIds ?: [0])
                ->orWhere(fn ($inner) => $inner->whereIn('kind', ClockEventKind::punchValues())->whereBetween('occurred_at', [$start, $end])))
            ->with('author:id,name')
            ->orderBy('seq')
            ->get();

        $voidedBy = ClockEvent::query()
            ->where('user_id', $subject->id)
            ->whereIn('voided_event_id', $rows->modelKeys() ?: [0])
            ->get(['id', 'voided_event_id', 'correction_id', 'occurred_at'])
            ->keyBy('voided_event_id');

        $history = [];
        foreach ($rows as $row) {
            $void = $voidedBy[$row->id] ?? null;
            // Del día: las anulaciones y lo añadido por sus correcciones, sus fichajes efectivos y
            // los anulados que eran suyos. Los fichajes de la jornada siguiente, no.
            $belongs = ! $row->kind->isPunch()
                || in_array($row->id, $effectiveIds, true)
                || in_array($row->correction_id, $correctionIds, true)
                || ($void !== null && (in_array($void->correction_id, $correctionIds, true) || LocalTime::dateOf($row->occurred_at) === $date));

            if (! $belongs) {
                continue;
            }

            $history[] = [
                ...WorkdayCalculator::event($row),
                'seq' => $row->seq,
                'recorded_at' => WorkdayCalculator::iso($row->recorded_at),
                'author' => $row->author?->name,
                'voided_event_id' => $row->voided_event_id,
                'voided' => $void !== null,
                'voided_by_correction_id' => $void?->correction_id,
                'voided_at' => WorkdayCalculator::iso($void?->occurred_at),
                'hash' => $row->hash,
            ];
        }

        $byId = $rows->keyBy('id');

        return [
            'date' => $date,
            'day' => $day,
            'history' => $history,
            'corrections' => $corrections->map(fn (ClockCorrection $correction): array => $this->correction($correction, $viewer, $subject, $byId->all()))->values()->all(),
            'can' => [
                'propose' => PeopleAccess::proposesFor($viewer, $subject)
                    && ! $corrections->contains(fn (ClockCorrection $correction): bool => $correction->status === CorrectionStatus::Pending),
            ],
            // §3.2.5: lo imputado ese día, solo a la propia persona (nunca a su responsable, L-11).
            'logged_minutes' => $viewer->id === $subject->id
                ? (int) TimeEntry::query()->where('user_id', $subject->id)->whereDate('date', $date)->sum('minutes')
                : null,
        ];
    }

    /**
     * Correcciones del registro de $subject que esperan SU conformidad (las propuso su responsable o
     * RR. HH.), si quien mira es ella.
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingSubject(User $subject, User $viewer): array
    {
        if ($subject->id !== $viewer->id) {
            return [];
        }

        return $this->list(ClockCorrection::query()
            ->pending()
            ->where('user_id', $subject->id)
            ->whereColumn('proposed_by', '!=', 'user_id')
            ->orderBy('date')
            ->get(), $viewer);
    }

    /**
     * Correcciones con el resumen de lo que anulan y añaden.
     *
     * @param  array<int, ClockCorrection>|EloquentCollection<int, ClockCorrection>  $corrections
     * @return list<array<string, mixed>>
     */
    public function list(array|EloquentCollection $corrections, User $viewer): array
    {
        $collection = new EloquentCollection(is_array($corrections) ? array_values($corrections) : $corrections->all());
        $collection->load(['proposer:id,name', 'decider:id,name', 'user:id,name,department_id']);

        $voidIds = $collection->flatMap(fn (ClockCorrection $correction): array => array_map(intval(...), $correction->voids))->unique()->values()->all();
        $events = ClockEvent::query()->whereIn('id', $voidIds ?: [0])->get()->keyBy('id')->all();

        return array_values(array_map(fn (ClockCorrection $correction): array => $this->correction($correction, $viewer, $correction->user, $events), $collection->all()));
    }

    /**
     * @param  array<int, ClockEvent>  $events  Fichajes ya leídos (los que anula).
     * @return array<string, mixed>
     */
    private function correction(ClockCorrection $correction, User $viewer, User $subject, array $events): array
    {
        $missing = array_values(array_diff(array_map(intval(...), $correction->voids), array_keys($events)));
        if ($missing !== []) {
            $events += ClockEvent::query()->whereIn('id', $missing)->get()->keyBy('id')->all();
        }

        $pending = $correction->status === CorrectionStatus::Pending;

        return [
            'id' => $correction->id,
            'user' => ['id' => $subject->id, 'name' => $subject->name],
            'date' => $correction->date->toDateString(),
            'status' => $correction->status->value,
            'reason' => $correction->reason,
            'proposed_by' => ['id' => $correction->proposed_by, 'name' => $correction->proposer->name],
            'proposed_by_subject' => $correction->proposedBySubject(),
            'proposed_at' => WorkdayCalculator::iso($correction->created_at),
            'voids' => array_values(array_filter(array_map(
                fn (int $id): ?array => isset($events[$id]) ? WorkdayCalculator::event($events[$id]) : null,
                array_map(intval(...), $correction->voids),
            ))),
            'adds' => array_map(fn (array $add): array => [
                'kind' => $add['kind'],
                'at' => CarbonImmutable::parse($add['occurred_at'])->utc()->toIso8601ZuluString(),
                'work_mode' => $add['work_mode'] ?? null,
            ], $correction->adds),
            'decided_by' => $correction->decider?->name,
            'decided_at' => WorkdayCalculator::iso($correction->decided_at),
            'decision_note' => $correction->decision_note,
            'dispute_reason' => $correction->dispute_reason,
            'expires_at' => $pending ? WorkdayCalculator::iso($correction->created_at?->addDays(ClockCorrection::ANSWER_DAYS)) : null,
            'can' => [
                'decide' => $pending && PeopleAccess::decides($viewer, $correction, $subject),
                'withdraw' => $pending && $correction->proposed_by === $viewer->id,
            ],
        ];
    }

    /**
     * Las filas del formulario de corrección de un día: sus fichajes efectivos.
     *
     * @return list<array{id: int, kind: string, at: string, work_mode: string|null}>
     */
    public function rows(User $subject, string $date): array
    {
        return array_map(fn (ClockEvent $event): array => [
            'id' => $event->id,
            'kind' => $event->kind->value,
            'at' => (string) WorkdayCalculator::iso($event->occurred_at),
            'work_mode' => $event->work_mode?->value,
        ], $this->corrections->dayEvents($subject->id, $date));
    }
}
