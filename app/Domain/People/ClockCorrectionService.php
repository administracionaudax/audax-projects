<?php

namespace App\Domain\People;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Enums\PauseType;
use App\Enums\WorkMode;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\User;
use App\Notifications\People\CorrectionAccepted;
use App\Notifications\People\CorrectionDisputed;
use App\Notifications\People\CorrectionRequested;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Correcciones del registro con doble conformidad (PLAN-FASE-11 §7.4; D-335; W-018, W-020, W-022 y
 * W-081). Como en Woffu, la persona «edita» su día y su responsable lo valida; a diferencia de
 * Woffu, si no hay acuerdo queda constancia de la discrepancia (borrador del RD):
 *
 * - **Proponer**: la persona, su responsable o RR. HH., con motivo obligatorio. La propuesta es lo
 *   que cambia frente a los fichajes efectivos del día: los que anula y los que añade (mover uno
 *   es anularlo y añadir otro). El día que resultaría tiene que ser una secuencia válida, sin
 *   fichajes en el futuro y sin pisar la jornada anterior ni la siguiente. Una pendiente por
 *   persona y día.
 * - **Aceptar o rechazar**: solo la otra parte (PeopleAccess::decides); nadie su propia propuesta.
 *   Al aceptar se vuelve a validar el día (ha podido cambiar) y ClockWriter escribe las anulaciones
 *   y los fichajes nuevos en la cadena. Rechazar exige un motivo y deja la corrección «en
 *   discrepancia»: no se aplica y cuenta la original.
 * - **Sin respuesta en 7 días**: en discrepancia por falta de respuesta (expireOverdue).
 * - **Retirar**: quien la propuso, mientras esté pendiente.
 *
 * Nada se sobrescribe: los fichajes originales siguen en la cadena (anulados, no borrados) y la
 * corrección queda sellada con su huella al decidirse.
 */
final class ClockCorrectionService
{
    public function __construct(
        private readonly ClockWriter $writer,
        private readonly RegisterHasher $hasher,
        private readonly RegisterTimeline $timeline,
    ) {}

    /**
     * Propone una corrección a partir de las filas del formulario: los fichajes del día como deben
     * quedar. Cada fila: `id` (si es un fichaje que ya estaba), `kind`, `time` (HH:MM de Madrid),
     * `next_day` (la hora es del día siguiente: una salida pasada la medianoche) y `work_mode`.
     *
     * @param  list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>  $rows
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function propose(User $actor, User $subject, string $date, array $rows, string $reason): ClockCorrection
    {
        if (! PeopleAccess::proposesFor($actor, $subject)) {
            throw new AuthorizationException;
        }

        $now = CarbonImmutable::now();

        if ($date > LocalTime::dateOf($now)) {
            throw ValidationException::withMessages(['date' => __('people.errors.future_day')]);
        }

        $this->assertDayOpen($subject, $date);

        $correction = DB::transaction(function () use ($actor, $subject, $date, $rows, $reason, $now): ClockCorrection {
            User::query()->whereKey($subject->id)->lockForUpdate()->value('id');

            if (ClockCorrection::query()->pending()->where('user_id', $subject->id)->where('date', $date)->exists()) {
                throw ValidationException::withMessages(['date' => __('people.errors.pending_exists')]);
            }

            $current = $this->dayEvents($subject->id, $date);
            [$voids, $adds] = $this->diff($current, $rows, $date);

            if ($voids === [] && $adds === []) {
                throw ValidationException::withMessages(['rows' => __('people.errors.no_changes')]);
            }

            $this->assertValidDay($subject->id, $date, $current, $voids, $adds, $now);

            $correction = new ClockCorrection;
            $correction->forceFill([
                'user_id' => $subject->id,
                'date' => $date,
                'proposed_by' => $actor->id,
                'reason' => trim($reason),
                'voids' => $voids,
                'adds' => $adds,
                'status' => CorrectionStatus::Pending,
            ])->save();

            return $correction;
        });

        $this->notify($correction->proposedBySubject()
            ? PeopleAccess::companyDeciders($subject)->all()
            : [$subject], new CorrectionRequested($correction, $actor->name, $subject->name));

        return $correction;
    }

    /**
     * La otra parte da su conformidad: se vuelve a validar el día y se escribe en la cadena.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function accept(User $actor, ClockCorrection $correction, ?string $note = null): ClockCorrection
    {
        $subject = User::query()->findOrFail($correction->user_id);

        $decided = DB::transaction(function () use ($actor, $correction, $subject, $note): ClockCorrection {
            $locked = $this->lockPending($correction);

            if (! PeopleAccess::decides($actor, $locked, $subject)) {
                throw new AuthorizationException;
            }

            $date = $locked->date->toDateString();
            $this->assertDayOpen($subject, $date);

            $current = $this->dayEvents($subject->id, $date);
            $currentIds = array_map(fn (ClockEvent $event): int => $event->id, $current);

            foreach ($locked->voids as $id) {
                if (! in_array((int) $id, $currentIds, true)) {
                    throw ValidationException::withMessages(['correction' => __('people.errors.day_changed')]);
                }
            }

            try {
                $this->assertValidDay($subject->id, $date, $current, $locked->voids, $locked->adds, CarbonImmutable::now());
            } catch (ValidationException) {
                throw ValidationException::withMessages(['correction' => __('people.errors.day_changed')]);
            }

            $this->writer->applyCorrection($locked, $subject, $actor, array_map(intval(...), $locked->voids), $locked->adds);

            return $this->decide($locked, CorrectionStatus::Accepted, $actor, $note);
        });

        $this->notify([User::query()->findOrFail($decided->proposed_by)], new CorrectionAccepted($decided, $actor->name));

        return $decided;
    }

    /**
     * La otra parte no está de acuerdo: queda en discrepancia, con su motivo y las dos versiones.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reject(User $actor, ClockCorrection $correction, string $note): ClockCorrection
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('people.errors.note_required')]);
        }

        $subject = User::query()->findOrFail($correction->user_id);

        $decided = DB::transaction(function () use ($actor, $correction, $subject, $note): ClockCorrection {
            $locked = $this->lockPending($correction);

            if (! PeopleAccess::decides($actor, $locked, $subject)) {
                throw new AuthorizationException;
            }

            return $this->decide($locked, CorrectionStatus::Disputed, $actor, $note, ClockCorrection::DISPUTE_REJECTED);
        });

        $this->notify([User::query()->findOrFail($decided->proposed_by)], new CorrectionDisputed($decided, $actor->name));

        return $decided;
    }

    /**
     * Quien la propuso la retira mientras está pendiente.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function withdraw(User $actor, ClockCorrection $correction): ClockCorrection
    {
        return DB::transaction(function () use ($actor, $correction): ClockCorrection {
            $locked = $this->lockPending($correction);

            if ($locked->proposed_by !== $actor->id) {
                throw new AuthorizationException;
            }

            return $this->decide($locked, CorrectionStatus::Withdrawn, $actor, null);
        });
    }

    /**
     * Las pendientes de hace más de 7 días pasan a discrepancia por falta de respuesta. Devuelve
     * cuántas.
     */
    public function expireOverdue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        $overdue = ClockCorrection::query()
            ->pending()
            ->where('created_at', '<=', $now->subDays(ClockCorrection::ANSWER_DAYS)->utc())
            ->orderBy('id')
            ->get();

        foreach ($overdue as $correction) {
            $decided = DB::transaction(function () use ($correction): ?ClockCorrection {
                $locked = ClockCorrection::query()->whereKey($correction->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== CorrectionStatus::Pending) {
                    return null;
                }

                return $this->decide($locked, CorrectionStatus::Disputed, null, null, ClockCorrection::DISPUTE_NO_ANSWER);
            });

            if ($decided !== null) {
                $count++;
                $this->notify(
                    User::query()->whereKey(array_unique([$decided->user_id, $decided->proposed_by]))->get()->all(),
                    new CorrectionDisputed($decided, null),
                );
            }
        }

        return $count;
    }

    /**
     * Un día de un mes ya confirmado no se corrige sin desconfirmarlo antes (PLAN-FASE-11 §7.4). Los
     * cierres mensuales llegan en R2: aquí irá la comprobación.
     */
    public function assertDayOpen(User $subject, string $date): void
    {
        // R2: comprobar que el mes de $date no está confirmado (month_closes).
    }

    /**
     * Fichajes efectivos de las jornadas que empiezan ese día.
     *
     * @return list<ClockEvent>
     */
    public function dayEvents(int $userId, string $date): array
    {
        $events = [];

        foreach ($this->timeline->workdaysByDate($userId, $date, $date)[$date] ?? [] as $workday) {
            foreach ($workday->events as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Lo que cambia entre los fichajes del día y las filas del formulario.
     *
     * @param  list<ClockEvent>  $current
     * @param  list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>  $rows
     * @return array{0: list<int>, 1: list<array{kind: string, occurred_at: string, work_mode: string|null, pause_type: string|null}>}
     *
     * @throws ValidationException
     */
    public function diff(array $current, array $rows, string $date): array
    {
        $byId = [];
        foreach ($current as $event) {
            $byId[$event->id] = $event;
        }

        $kept = [];
        $voids = [];
        $adds = [];

        foreach ($rows as $index => $row) {
            $kind = ClockEventKind::tryFrom($row['kind']);

            if ($kind === null || ! $kind->isPunch()) {
                throw ValidationException::withMessages(["rows.{$index}.kind" => __('people.errors.invalid_kind')]);
            }

            $instant = self::instant($date, $row['time'], (bool) ($row['next_day'] ?? false), $index);
            $mode = $kind->takesWorkMode() ? (WorkMode::tryFrom((string) ($row['work_mode'] ?? '')) ?? WorkMode::OnSite) : null;
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id !== null && isset($byId[$id])) {
                $original = $byId[$id];
                $same = $original->kind === $kind
                    && $original->occurred_at->utc()->format('Y-m-d H:i') === $instant->utc()->format('Y-m-d H:i')
                    && $original->work_mode === $mode;

                if ($same) {
                    $kept[$id] = true;

                    continue;
                }
            }

            $adds[] = [
                'kind' => $kind->value,
                'occurred_at' => $instant->utc()->toIso8601ZuluString(),
                'work_mode' => $mode?->value,
                'pause_type' => $kind === ClockEventKind::PauseStart ? PauseType::Meal->value : null,
            ];
        }

        foreach ($current as $event) {
            if (! isset($kept[$event->id])) {
                $voids[] = $event->id;
            }
        }

        return [$voids, $adds];
    }

    /**
     * El día que resultaría de aplicar la corrección tiene que ser una secuencia válida: empieza
     * por una entrada, alterna bien, no queda abierto si el día ya ha pasado, no tiene fichajes en
     * el futuro, las entradas son de ese día y no pisa la jornada anterior ni la siguiente.
     *
     * @param  list<ClockEvent>  $current
     * @param  list<int>|array<int, mixed>  $voids
     * @param  list<array{kind: string, occurred_at: string, work_mode: string|null, pause_type: string|null}>|array<int, mixed>  $adds
     *
     * @throws ValidationException
     */
    public function assertValidDay(int $userId, string $date, array $current, array $voids, array $adds, CarbonImmutable $now): void
    {
        $voided = array_flip(array_map(intval(...), $voids));
        /** @var list<array{kind: ClockEventKind, at: CarbonImmutable, order: int}> $sequence */
        $sequence = [];

        foreach ($current as $event) {
            if (! isset($voided[$event->id])) {
                $sequence[] = ['kind' => $event->kind, 'at' => $event->occurred_at, 'order' => 0];
            }
        }

        foreach (array_values($adds) as $index => $add) {
            $sequence[] = ['kind' => ClockEventKind::from($add['kind']), 'at' => CarbonImmutable::parse($add['occurred_at']), 'order' => $index + 1];
        }

        usort($sequence, fn (array $a, array $b): int => [$a['at']->getTimestamp(), self::rank($a['kind']), $a['order']] <=> [$b['at']->getTimestamp(), self::rank($b['kind']), $b['order']]);

        $state = null;
        foreach ($sequence as $item) {
            if ($item['at']->greaterThan($now)) {
                throw ValidationException::withMessages(['rows' => __('people.errors.future_time')]);
            }

            if ($item['kind'] === ClockEventKind::ClockIn && LocalTime::dateOf($item['at']) !== $date) {
                throw ValidationException::withMessages(['rows' => __('people.errors.clock_in_other_day')]);
            }

            $state = match ([$state, $item['kind']]) {
                [null, ClockEventKind::ClockIn], ['out', ClockEventKind::ClockIn] => 'working',
                ['working', ClockEventKind::PauseStart] => 'paused',
                ['paused', ClockEventKind::PauseEnd] => 'working',
                ['working', ClockEventKind::ClockOut], ['paused', ClockEventKind::ClockOut] => 'out',
                default => throw ValidationException::withMessages(['rows' => __('people.errors.invalid_sequence')]),
            };
        }

        if ($state !== null && $state !== 'out' && $date < LocalTime::dateOf($now)) {
            throw ValidationException::withMessages(['rows' => __('people.errors.day_left_open')]);
        }

        if ($sequence === []) {
            return;
        }

        // Ningún fichaje de otra jornada puede quedar entre el primero y el último del día.
        $overlaps = RegisterTimeline::effectiveQuery($userId)
            ->whereNotIn('id', array_map(fn (ClockEvent $event): int => $event->id, $current) ?: [0])
            ->whereBetween('occurred_at', [$sequence[0]['at']->utc(), $sequence[count($sequence) - 1]['at']->utc()])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['rows' => __('people.errors.overlaps')]);
        }
    }

    /**
     * Instante UTC de una hora de Madrid en ese día (o el siguiente).
     *
     * @throws ValidationException
     */
    private static function instant(string $date, string $time, bool $nextDay, int $index): CarbonImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw ValidationException::withMessages(["rows.{$index}.time" => __('people.errors.invalid_time')]);
        }

        $day = CarbonImmutable::parse($date, LocalTime::timezone());

        return CarbonImmutable::parse(($nextDay ? $day->addDay() : $day)->toDateString().' '.$time, LocalTime::timezone());
    }

    /** Orden de los fichajes del mismo minuto: entrada, pausa, vuelta y salida. */
    private static function rank(ClockEventKind $kind): int
    {
        return match ($kind) {
            ClockEventKind::ClockIn => 0,
            ClockEventKind::PauseStart => 1,
            ClockEventKind::PauseEnd => 2,
            ClockEventKind::ClockOut => 3,
            ClockEventKind::Void => 4,
        };
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(ClockCorrection $correction): ClockCorrection
    {
        $locked = ClockCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== CorrectionStatus::Pending) {
            throw ValidationException::withMessages(['correction' => __('people.errors.already_decided')]);
        }

        return $locked;
    }

    private function decide(ClockCorrection $correction, CorrectionStatus $status, ?User $actor, ?string $note, ?string $dispute = null): ClockCorrection
    {
        $correction->forceFill([
            'status' => $status,
            'decided_by' => $actor?->id,
            'decided_at' => CarbonImmutable::now()->utc()->startOfSecond(),
            'decision_note' => $note === null || trim($note) === '' ? null : trim($note),
            'dispute_reason' => $dispute,
        ]);
        $correction->hash = $this->hasher->correction($correction);
        $correction->save();

        return $correction;
    }

    /**
     * Avisa (solo con el módulo encendido de verdad: en modo de prueba no se avisa a nadie, D-239).
     *
     * @param  list<User>  $recipients
     */
    private function notify(array $recipients, object $notification): void
    {
        if ($recipients === [] || ! AppModules::enabled(AppModule::People)) {
            return;
        }

        Notification::send($recipients, $notification);
    }
}
