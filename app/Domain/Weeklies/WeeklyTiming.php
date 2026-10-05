<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyCycleProgress;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyPersonStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Plazos y estados de la weekly (F-042, F-066, F-100 y F-136), portados de `ws:src/lib/weekTiming.ts`
 * y de las pantallas ReportList, TeamView y WeeklysList. Lógica pura: sin base de datos.
 *
 * - El plazo es un DÍA: se cumple hasta el final de ese día en Europe/Madrid (23:59:59.999999).
 * - «Próximamente»: antes del día laborable anterior al plazo (el jueves, si vence el viernes).
 * - «Con retraso»: pasado el final del día del plazo con la semana aún activa.
 */
final class WeeklyTiming
{
    /** Último instante del día del plazo, en Madrid. */
    public function deadlineEnd(CarbonInterface|string $deadlineDate): CarbonImmutable
    {
        return $this->day($deadlineDate)->endOfDay();
    }

    /**
     * Inicio de la ventana «Pendiente»: las 00:00 del día laborable anterior al plazo (sábados y
     * domingos se saltan, como en `getWeekPendingStart`).
     */
    public function pendingStart(CarbonInterface|string $deadlineDate): CarbonImmutable
    {
        $day = $this->day($deadlineDate)->subDay();

        while ($day->isWeekend()) {
            $day = $day->subDay();
        }

        return $day->startOfDay();
    }

    public function isUpcoming(CarbonInterface|string $deadlineDate, CarbonInterface $now): bool
    {
        return $now->lessThan($this->pendingStart($deadlineDate));
    }

    public function isOverdue(CarbonInterface|string $deadlineDate, CarbonInterface $now): bool
    {
        return $now->greaterThan($this->deadlineEnd($deadlineDate));
    }

    /** A tiempo = enviado antes del final del día del plazo (F-100). */
    public function isOnTime(CarbonInterface $submittedAt, CarbonInterface|string $deadlineDate): bool
    {
        return $submittedAt->lessThanOrEqualTo($this->deadlineEnd($deadlineDate));
    }

    /**
     * Estado de la weekly de una persona en una semana (F-042 y F-136), con la precedencia de
     * WeeklySync: exenta → enviada (a tiempo o con retraso) → próximamente → con retraso → no enviada
     * (semana cerrada) → pendiente. Si no debía enviar (alta posterior o no participa): no requerida.
     *
     * @param  bool  $required  ¿Le tocaba enviar esa semana? (WeeklyRoster::participates)
     */
    public function personStatus(
        WeeklyCycleStatus $cycleStatus,
        CarbonInterface|string $deadlineDate,
        ?CarbonInterface $submittedAt,
        bool $exempt,
        bool $required,
        CarbonInterface $now,
    ): WeeklyPersonStatus {
        if ($exempt) {
            return WeeklyPersonStatus::Exempt;
        }

        if ($submittedAt !== null) {
            return $this->isOnTime($submittedAt, $deadlineDate)
                ? WeeklyPersonStatus::Submitted
                : WeeklyPersonStatus::SubmittedLate;
        }

        if (! $required) {
            return WeeklyPersonStatus::NotRequired;
        }

        $closed = $cycleStatus === WeeklyCycleStatus::Closed;

        if (! $closed && $this->isUpcoming($deadlineDate, $now)) {
            return WeeklyPersonStatus::Upcoming;
        }

        if (! $closed && $this->isOverdue($deadlineDate, $now)) {
            return WeeklyPersonStatus::Overdue;
        }

        return $closed ? WeeklyPersonStatus::Missed : WeeklyPersonStatus::Pending;
    }

    /**
     * Estado de una semana en el histórico (F-066): cerrada → finalizada; si no, próximamente, con
     * retraso, completada (han enviado todos los que debían) o por completar.
     */
    public function cycleProgress(
        WeeklyCycleStatus $cycleStatus,
        CarbonInterface|string $deadlineDate,
        int $submitted,
        int $expected,
        CarbonInterface $now,
    ): WeeklyCycleProgress {
        if ($cycleStatus === WeeklyCycleStatus::Closed) {
            return WeeklyCycleProgress::Finished;
        }

        if ($this->isUpcoming($deadlineDate, $now)) {
            return WeeklyCycleProgress::Upcoming;
        }

        if ($this->isOverdue($deadlineDate, $now)) {
            return WeeklyCycleProgress::Overdue;
        }

        return $submitted >= $expected ? WeeklyCycleProgress::Completed : WeeklyCycleProgress::InProgress;
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        $day = is_string($date)
            ? substr($date, 0, 10)
            : CarbonImmutable::instance($date)->setTimezone(WeeklyCalendar::TIMEZONE)->toDateString();

        return CarbonImmutable::parse($day, WeeklyCalendar::TIMEZONE)->startOfDay();
    }
}
