<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\WorkMode;
use App\Models\ClockEvent;
use Carbon\CarbonImmutable;

/**
 * Una jornada del registro (D-333): de una entrada a su salida, con sus pausas. Pertenece al día de
 * Madrid de su entrada, aunque acabe pasada la medianoche (W-021). Los tramos se miden con
 * instantes UTC: en el cambio de hora cuenta el tiempo real transcurrido.
 *
 * Una jornada sin salida está «abierta»: en curso si empezó hace menos de ClockWriter::STALE_HOURS,
 * y sin cerrar (incidencia «Falta la salida») si no.
 */
final class Workday
{
    /** @var list<ClockEvent> */
    public array $events = [];

    /** @var list<array{kind: 'work'|'pause', from: CarbonImmutable, to: CarbonImmutable|null, mode: WorkMode|null}> */
    public array $segments = [];

    /** ¿Fichó la salida estando en la pausa? (incidencia «Pausa abierta»). */
    public bool $pauseOpenAtClockOut = false;

    /** ¿Hay fichajes fuera de orden? Solo podría venir de datos antiguos: se ignoran al contar. */
    public bool $irregular = false;

    public function __construct(
        public readonly string $date,
        public readonly CarbonImmutable $clockInAt,
    ) {}

    public function clockOutAt(): ?CarbonImmutable
    {
        $last = $this->events[count($this->events) - 1] ?? null;

        return $last !== null && $last->kind === ClockEventKind::ClockOut ? $last->occurred_at : null;
    }

    public function isOpen(): bool
    {
        return $this->clockOutAt() === null;
    }

    /** ¿Está en la pausa ahora (abierta y el último fichaje es el inicio de la pausa)? */
    public function isPaused(): bool
    {
        $last = $this->events[count($this->events) - 1] ?? null;

        return $this->isOpen() && $last !== null && $last->kind === ClockEventKind::PauseStart;
    }

    /** Segundos trabajados hasta $now (los tramos abiertos cuentan hasta $now). */
    public function workedSeconds(?CarbonImmutable $now = null): int
    {
        return $this->sum('work', $now);
    }

    public function pauseSeconds(?CarbonImmutable $now = null): int
    {
        return $this->sum('pause', $now);
    }

    /** El tramo de trabajo más largo sin pausa, en segundos (art. 34.4 ET). */
    public function longestStretchSeconds(?CarbonImmutable $now = null): int
    {
        $longest = 0;

        foreach ($this->segments as $segment) {
            if ($segment['kind'] === 'work') {
                $longest = max($longest, self::seconds($segment, $now));
            }
        }

        return $longest;
    }

    /**
     * Modos de los tramos de trabajo, sin repetir.
     *
     * @return list<WorkMode>
     */
    public function modes(): array
    {
        $modes = [];

        foreach ($this->segments as $segment) {
            if ($segment['kind'] === 'work' && $segment['mode'] !== null && ! in_array($segment['mode'], $modes, true)) {
                $modes[] = $segment['mode'];
            }
        }

        return $modes;
    }

    /** Modo del último tramo de trabajo. */
    public function lastMode(): ?WorkMode
    {
        for ($index = count($this->segments) - 1; $index >= 0; $index--) {
            if ($this->segments[$index]['kind'] === 'work' && $this->segments[$index]['mode'] !== null) {
                return $this->segments[$index]['mode'];
            }
        }

        return null;
    }

    private function sum(string $kind, ?CarbonImmutable $now): int
    {
        $total = 0;

        foreach ($this->segments as $segment) {
            if ($segment['kind'] === $kind) {
                $total += self::seconds($segment, $now);
            }
        }

        return $total;
    }

    /**
     * @param  array{kind: 'work'|'pause', from: CarbonImmutable, to: CarbonImmutable|null, mode: WorkMode|null}  $segment
     */
    private static function seconds(array $segment, ?CarbonImmutable $now): int
    {
        $to = $segment['to'] ?? $now;

        if ($to === null) {
            return 0;
        }

        return max($to->getTimestamp() - $segment['from']->getTimestamp(), 0);
    }
}
