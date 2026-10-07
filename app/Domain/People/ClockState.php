<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\ClockStatus;
use App\Enums\WorkMode;
use App\Models\ClockEvent;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Estado del registro de una persona ahora (el botón de la cabecera y las reglas de ClockWriter,
 * D-333), a partir de sus fichajes efectivos de las últimas 48 h:
 * - la última jornada abierta y reciente (menos de ClockWriter::STALE_HOURS desde su entrada):
 *   trabajando o en pausa,
 * - si la última jornada se cerró hoy (en Madrid): jornada cerrada,
 * - si no: sin jornada. Si la última se quedó abierta y ya es antigua, `unclosedDate` es su día
 *   (la cabecera sugiere proponer la salida con una corrección; nunca se cierra sola).
 */
final readonly class ClockState
{
    /**
     * @param  list<Workday>  $todayWorkdays  Las jornadas que empiezan hoy (Madrid), para el total.
     */
    public function __construct(
        public ClockStatus $status,
        public ?Workday $current,
        public array $todayWorkdays,
        public ?WorkMode $lastMode,
        public ?string $unclosedDate,
        public CarbonImmutable $now,
    ) {}

    public static function of(User $user, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $events = (new RegisterTimeline)->effective($user->id, $now->subHours(48), $now);
        $workdays = RegisterTimeline::build($events);
        $last = $workdays[count($workdays) - 1] ?? null;
        $today = LocalTime::dateOf($now);
        $todayWorkdays = array_values(array_filter($workdays, fn (Workday $workday): bool => $workday->date === $today));
        $lastMode = self::lastMode($user, $workdays);

        if ($last === null) {
            return new self(ClockStatus::Off, null, $todayWorkdays, $lastMode, null, $now);
        }

        if ($last->isOpen()) {
            if (self::isStale($last, $now)) {
                return new self(ClockStatus::Off, null, $todayWorkdays, $lastMode, $last->date, $now);
            }

            return new self($last->isPaused() ? ClockStatus::Paused : ClockStatus::Working, $last, $todayWorkdays, $lastMode, null, $now);
        }

        $closedToday = LocalTime::dateOf($last->clockOutAt() ?? $now) === $today;

        return new self($closedToday ? ClockStatus::Closed : ClockStatus::Off, null, $todayWorkdays, $lastMode, null, $now);
    }

    /** ¿Es una jornada abierta que ya no puede seguir en curso (más de STALE_HOURS)? */
    public static function isStale(Workday $workday, CarbonImmutable $now): bool
    {
        return $workday->isOpen() && $workday->clockInAt->lessThanOrEqualTo($now->subHours(ClockWriter::STALE_HOURS));
    }

    /**
     * Segundos trabajados hoy, contando el tramo en curso hasta ahora (y la jornada en curso aunque
     * empezara ayer y cruce la medianoche).
     */
    public function workedTodaySeconds(): int
    {
        $total = 0;

        foreach ($this->counted() as $workday) {
            $running = $this->current !== null && $workday === $this->current;
            $total += $workday->workedSeconds($running ? $this->now : null);
        }

        return $total;
    }

    /** Segundos trabajados hoy SIN el tramo en curso (la interfaz suma el tramo con su reloj). */
    public function closedSecondsToday(): int
    {
        $total = 0;

        foreach ($this->counted() as $workday) {
            $total += $workday->workedSeconds();
        }

        return $total;
    }

    /**
     * Jornadas que suman en el total de hoy: las que empiezan hoy y la que está en curso.
     *
     * @return list<Workday>
     */
    private function counted(): array
    {
        if ($this->current === null || in_array($this->current, $this->todayWorkdays, true)) {
            return $this->todayWorkdays;
        }

        return [...$this->todayWorkdays, $this->current];
    }

    /** Desde cuándo está en el estado actual (la última entrada, pausa, vuelta o salida). */
    public function since(): ?CarbonImmutable
    {
        if ($this->current !== null) {
            return $this->current->events[count($this->current->events) - 1]->occurred_at;
        }

        if ($this->status === ClockStatus::Closed) {
            $last = $this->todayWorkdays[count($this->todayWorkdays) - 1] ?? null;

            return $last?->clockOutAt();
        }

        return null;
    }

    /** Inicio del tramo de trabajo en curso, si está trabajando. */
    public function runningSince(): ?CarbonImmutable
    {
        return $this->status === ClockStatus::Working ? $this->since() : null;
    }

    /**
     * Modo para proponer al fichar: el último que usó (PLAN-FASE-11 §7.1), o presencial.
     *
     * @param  list<Workday>  $workdays
     */
    private static function lastMode(User $user, array $workdays): ?WorkMode
    {
        for ($index = count($workdays) - 1; $index >= 0; $index--) {
            if (($mode = $workdays[$index]->lastMode()) !== null) {
                return $mode;
            }
        }

        $kind = ClockEventKind::PauseEnd->value;
        $last = RegisterTimeline::effectiveQuery($user->id)
            ->whereIn('kind', [ClockEventKind::ClockIn->value, $kind])
            ->whereNotNull('work_mode')
            ->orderByDesc('occurred_at')
            ->first(['id', 'work_mode']);

        return $last instanceof ClockEvent ? $last->work_mode : null;
    }
}
