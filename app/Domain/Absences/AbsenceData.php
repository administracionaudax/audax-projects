<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceType;

/**
 * Datos de una ausencia que se solicita o se registra (D-049). Fechas locales Y-m-d; con
 * partial_minutes, la ausencia es de parte de UN día (end = start).
 */
final readonly class AbsenceData
{
    public function __construct(
        public AbsenceType $type,
        public string $startDate,
        public string $endDate,
        public ?int $partialMinutes = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array{type: string, start_date: string, end_date?: string|null, partial_minutes?: int|string|null, notes?: string|null}  $input
     */
    public static function fromInput(array $input): self
    {
        $partial = $input['partial_minutes'] ?? null;
        $notes = isset($input['notes']) ? trim((string) $input['notes']) : '';

        return new self(
            type: AbsenceType::from($input['type']),
            startDate: $input['start_date'],
            endDate: ($input['end_date'] ?? null) ?: $input['start_date'],
            partialMinutes: $partial === null || $partial === '' ? null : (int) $partial,
            notes: $notes === '' ? null : $notes,
        );
    }

    /**
     * @return array{type: AbsenceType, start_date: string, end_date: string, partial_minutes: int|null, notes: string|null}
     */
    public function attributes(): array
    {
        return [
            'type' => $this->type,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'partial_minutes' => $this->partialMinutes,
            'notes' => $this->notes,
        ];
    }
}
