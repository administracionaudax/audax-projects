<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceType;
use App\Models\LeaveType;

/**
 * Datos de una ausencia que se solicita o se registra (D-049). Fechas locales Y-m-d; con
 * partial_minutes, la ausencia es de parte de UN día (end = start).
 *
 * Fase 11, R3 (D-360 y D-361): el tipo del catálogo (`leaveType`; su categoría es `type`) y, en las
 * de horas, la franja (HH:MM de Madrid): con ella, partial_minutes son los minutos de la franja.
 * Sin `leave_type_id` (el formulario de la Fase 3), el tipo es el de la categoría.
 */
final readonly class AbsenceData
{
    public function __construct(
        public AbsenceType $type,
        public string $startDate,
        public string $endDate,
        public ?int $partialMinutes = null,
        public ?string $notes = null,
        public ?LeaveType $leaveType = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
    ) {}

    /**
     * @param  array{type?: string|null, leave_type_id?: int|string|null, start_date: string, end_date?: string|null, partial_minutes?: int|string|null, start_time?: string|null, end_time?: string|null, notes?: string|null}  $input
     */
    public static function fromInput(array $input): self
    {
        $partial = $input['partial_minutes'] ?? null;
        $notes = isset($input['notes']) ? trim((string) $input['notes']) : '';
        $leaveTypeId = $input['leave_type_id'] ?? null;
        $leaveType = $leaveTypeId !== null && $leaveTypeId !== ''
            ? LeaveType::query()->find((int) $leaveTypeId)
            : null;
        $type = $leaveType !== null ? $leaveType->category : AbsenceType::from((string) ($input['type'] ?? AbsenceType::Other->value));
        $leaveType ??= LeaveType::query()->where('key', $type->value)->first();

        $startTime = self::time($input['start_time'] ?? null);
        $endTime = self::time($input['end_time'] ?? null);
        $partialMinutes = $partial === null || $partial === '' ? null : (int) $partial;

        // Con franja, las horas son las de la franja (si está bien; si no, lo dice AbsenceRules).
        if ($startTime !== null && $endTime !== null && $endTime > $startTime) {
            $partialMinutes = self::minutes($endTime) - self::minutes($startTime);
        }

        $startDate = $input['start_date'];

        return new self(
            type: $type,
            startDate: $startDate,
            endDate: $startTime !== null || $endTime !== null ? $startDate : (($input['end_date'] ?? null) ?: $startDate),
            partialMinutes: $partialMinutes,
            notes: $notes === '' ? null : $notes,
            leaveType: $leaveType,
            startTime: $startTime,
            endTime: $endTime,
        );
    }

    /**
     * @return array{type: AbsenceType, leave_type_id: int|null, start_date: string, end_date: string, partial_minutes: int|null, start_time: string|null, end_time: string|null, notes: string|null}
     */
    public function attributes(): array
    {
        return [
            'type' => $this->type,
            'leave_type_id' => $this->leaveType?->id,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'partial_minutes' => $this->partialMinutes,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'notes' => $this->notes,
        ];
    }

    /** ¿Lleva franja horaria? */
    public function hasSlot(): bool
    {
        return $this->startTime !== null || $this->endTime !== null;
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value)) !== 1) {
            return null;
        }

        return trim($value);
    }
}
