<?php

namespace App\Domain\Weeklies;

use Carbon\CarbonImmutable;

/**
 * Fechas, número y etiqueta de una semana de la weekly (lunes a viernes). Lo da WeeklyCalendar.
 * Las fechas son días (a medianoche de Europe/Madrid), sin hora.
 */
final readonly class WeeklyPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $deadline,
        public string $number,
        public string $label,
    ) {}

    /**
     * Atributos para crear un WeeklyCycle.
     *
     * @return array{number: string, label: string, start_date: string, end_date: string, deadline_date: string}
     */
    public function toAttributes(): array
    {
        return [
            'number' => $this->number,
            'label' => $this->label,
            'start_date' => $this->start->toDateString(),
            'end_date' => $this->end->toDateString(),
            'deadline_date' => $this->deadline->toDateString(),
        ];
    }
}
