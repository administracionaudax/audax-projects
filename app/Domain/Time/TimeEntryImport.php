<?php

namespace App\Domain\Time;

use App\Enums\TimeEntryStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Una entrada que llega de otra herramienta (importación de ClickUp, D-136). La tarea viene ya
 * cargada con su proyecto; el estado y sus fechas de aprobación y bloqueo los decide el importador.
 * `isBillable` null = se hereda de la tarea (y nunca facturable en proyectos internos).
 */
final readonly class TimeEntryImport
{
    public function __construct(
        public int $userId,
        public Task $task,
        public CarbonImmutable $date,
        public int $minutes,
        public ?string $description = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?bool $isBillable = null,
        public TimeEntryStatus $status = TimeEntryStatus::Draft,
        public ?CarbonImmutable $approvedAt = null,
        public ?CarbonImmutable $lockedAt = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
