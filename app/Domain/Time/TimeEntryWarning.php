<?php

namespace App\Domain\Time;

/**
 * Aviso no bloqueante al imputar (SPEC §7 y §8.6). Se muestra al usuario como toast o en el
 * formulario; la entrada se guarda igualmente.
 */
final readonly class TimeEntryWarning
{
    public const string TASK_COMPLETED = 'task_completed';

    public const string OVER_CAPACITY = 'over_capacity';

    public const string OVERAGE = 'overage';

    /** Día con una ausencia aprobada de la persona (SPEC §7, D-049). Añadido por la Fase 3. */
    public const string ABSENCE = 'absence';

    public function __construct(
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
