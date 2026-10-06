<?php

namespace App\Domain\Time;

use Carbon\CarbonImmutable;

/**
 * Datos de una imputación (entrada manual, hoja semanal o temporizador).
 * `isBillable` null = se hereda de la tarea (y nunca facturable en proyectos internos).
 */
final readonly class TimeEntryData
{
    public function __construct(
        public int $userId,
        public int $taskId,
        public CarbonImmutable $date,
        public int $minutes,
        public ?string $description = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?bool $isBillable = null,
        /** Línea del plan del día de la que sale (D-254): solo un enlace, no cambia ninguna regla. */
        public ?int $dayPlanItemId = null,
    ) {}
}
