<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\ClockStatus;
use App\Enums\PauseType;
use App\Enums\WorkMode;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * El ÚNICO que escribe en el registro de jornada (Fase 11, D-332 y D-333), como TimeEntryWriter
 * con las horas:
 *
 * - **Fichar** (`punch`): la propia persona, en el momento y con la **hora del servidor** (nunca
 *   la del navegador, que ni se recibe). Valida la secuencia con el estado actual (ClockState): no
 *   hay dos entradas seguidas, ni una pausa sin entrada, ni una vuelta sin pausa. Se puede salir
 *   desde la pausa (queda la incidencia «Pausa abierta»). Tras una salida se puede volver a entrar
 *   el mismo día (jornada partida). Una jornada abierta hace más de STALE_HOURS deja de estar en
 *   curso: se puede fichar una entrada nueva y la anterior queda con la incidencia «Falta la
 *   salida». **Nunca** se cierra una jornada sola ni se crea un fichaje que no haya hecho alguien.
 * - **Aplicar una corrección aceptada** (`applyCorrection`, solo desde ClockCorrectionService):
 *   una fila `void` por cada fichaje que anula y una fila nueva por cada fichaje que añade, con el
 *   instante acordado y la marca de la corrección.
 *
 * Cada fila se encadena con la anterior de la persona (`seq`, `prev_hash`, `hash`) dentro de una
 * transacción con la persona bloqueada: nunca dos filas con el mismo `seq` ni una cadena partida.
 */
final class ClockWriter
{
    /** Horas tras la entrada en las que una jornada sin salida deja de estar en curso (D-333). */
    public const int STALE_HOURS = 16;

    public function __construct(private readonly RegisterHasher $hasher) {}

    /**
     * Ficha ahora (hora del servidor).
     *
     * @throws ValidationException
     */
    public function punch(
        User $user,
        ClockEventKind $kind,
        ?WorkMode $mode = null,
        ClockSource $source = ClockSource::Web,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ClockEvent {
        if (! $kind->isPunch() || $source === ClockSource::Correction) {
            throw new InvalidArgumentException('Fichar solo admite entradas, pausas y salidas de la web o la app.');
        }

        if (! PeopleAccess::subject($user)) {
            throw ValidationException::withMessages(['clock' => __('people.errors.not_subject')]);
        }

        return DB::transaction(function () use ($user, $kind, $mode, $source, $ip, $userAgent): ClockEvent {
            $this->lock($user);

            $now = CarbonImmutable::now()->utc()->startOfSecond();
            $state = ClockState::of($user, $now);

            $this->assertTransition($state->status, $kind);
            $this->assertNotBeforeLast($user, $now);

            return $this->append($user, [
                'kind' => $kind,
                'occurred_at' => $now,
                'work_mode' => $kind->takesWorkMode() ? ($mode ?? $state->lastMode ?? WorkMode::OnSite) : null,
                'pause_type' => $kind === ClockEventKind::PauseStart ? PauseType::Meal : null,
                'source' => $source,
                'created_by' => $user->id,
                'ip_hash' => RegisterHasher::ipHash($ip),
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 157),
            ], $now);
        });
    }

    /**
     * Escribe lo acordado en una corrección: primero las anulaciones y después los fichajes nuevos.
     * Lo llama ClockCorrectionService dentro de su transacción, tras validar el día resultante.
     *
     * @param  list<int>  $voids  Ids de fichajes efectivos de la persona.
     * @param  list<array{kind: string, occurred_at: string, work_mode: string|null, pause_type: string|null}>  $adds
     * @return list<ClockEvent>
     */
    public function applyCorrection(ClockCorrection $correction, User $subject, User $decider, array $voids, array $adds): array
    {
        $this->lock($subject);

        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $written = [];

        foreach ($voids as $eventId) {
            $written[] = $this->append($subject, [
                'kind' => ClockEventKind::Void,
                'occurred_at' => $now,
                'work_mode' => null,
                'pause_type' => null,
                'source' => ClockSource::Correction,
                'voided_event_id' => $eventId,
                'correction_id' => $correction->id,
                'created_by' => $decider->id,
                'ip_hash' => null,
                'user_agent' => null,
            ], $now);
        }

        foreach ($adds as $add) {
            $kind = ClockEventKind::from($add['kind']);

            $written[] = $this->append($subject, [
                'kind' => $kind,
                'occurred_at' => CarbonImmutable::parse($add['occurred_at'])->utc()->startOfSecond(),
                'work_mode' => $kind->takesWorkMode() ? WorkMode::from($add['work_mode'] ?? WorkMode::OnSite->value) : null,
                'pause_type' => $kind === ClockEventKind::PauseStart ? PauseType::from($add['pause_type'] ?? PauseType::Meal->value) : null,
                'source' => ClockSource::Correction,
                'correction_id' => $correction->id,
                'created_by' => $decider->id,
                'ip_hash' => null,
                'user_agent' => null,
            ], $now);
        }

        return $written;
    }

    /**
     * @throws ValidationException
     */
    private function assertTransition(ClockStatus $status, ClockEventKind $kind): void
    {
        $allowed = match ($kind) {
            ClockEventKind::ClockIn => in_array($status, [ClockStatus::Off, ClockStatus::Closed], true),
            ClockEventKind::PauseStart => $status === ClockStatus::Working,
            ClockEventKind::PauseEnd => $status === ClockStatus::Paused,
            ClockEventKind::ClockOut => in_array($status, [ClockStatus::Working, ClockStatus::Paused], true),
            ClockEventKind::Void => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages(['clock' => __("people.errors.transition.{$kind->value}.{$status->value}")]);
        }
    }

    /**
     * Un fichaje nunca queda antes del último efectivo de la persona (p. ej., si el reloj del
     * servidor retrocediera): la secuencia tiene que seguir el orden del tiempo.
     *
     * @throws ValidationException
     */
    private function assertNotBeforeLast(User $user, CarbonImmutable $now): void
    {
        $last = RegisterTimeline::effectiveQuery($user->id)->max('occurred_at');

        if ($last !== null && CarbonImmutable::parse((string) $last, 'UTC')->greaterThan($now)) {
            throw ValidationException::withMessages(['clock' => __('people.errors.clock_behind')]);
        }
    }

    private function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->value('id');
    }

    /**
     * Añade una fila al final de la cadena de la persona.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function append(User $user, array $attributes, CarbonImmutable $now): ClockEvent
    {
        $previous = ClockEvent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first(['id', 'seq', 'hash']);

        $event = new ClockEvent;
        $event->forceFill([
            'voided_event_id' => null,
            'correction_id' => null,
            ...$attributes,
            'user_id' => $user->id,
            'seq' => ($previous->seq ?? 0) + 1,
            'recorded_at' => $now,
            'prev_hash' => $previous->hash ?? RegisterHasher::GENESIS,
            'created_at' => $now,
        ]);
        $event->hash = $this->hasher->event($event);
        $event->save();

        return $event;
    }
}
