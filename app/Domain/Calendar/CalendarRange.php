<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Días visibles del calendario del equipo (D-144), de lunes a domingo como en el resto de la app:
 * - mes: del lunes de la semana del día 1 al domingo de la semana del último día (4 a 6 semanas),
 * - semana: la de la fecha, de lunes a domingo,
 * - día: la fecha.
 * Fechas locales Y-m-d, sin zona horaria (las de las tareas no se convierten).
 */
final readonly class CalendarRange
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function for(string $view, string $date): self
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: CarbonImmutable::today();

        return match ($view) {
            CalendarFilters::MONTH => new self(
                $day->startOfMonth()->startOfWeek(CarbonInterface::MONDAY),
                $day->endOfMonth()->startOfDay()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay(),
            ),
            CalendarFilters::DAY => new self($day, $day),
            default => new self(
                $day->startOfWeek(CarbonInterface::MONDAY),
                $day->startOfWeek(CarbonInterface::MONDAY)->addDays(6),
            ),
        };
    }

    public function fromString(): string
    {
        return $this->from->toDateString();
    }

    public function toString(): string
    {
        return $this->to->toDateString();
    }

    /**
     * El día siguiente al último: las fechas se comparan con «< este día» (vale igual con un `date`
     * de PostgreSQL y con el «AAAA-MM-DD 00:00:00» de SQLite).
     */
    public function endExclusive(): string
    {
        return $this->to->addDay()->toDateString();
    }

    /**
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; $day <= $this->to; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
