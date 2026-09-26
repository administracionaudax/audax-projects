<?php

namespace App\Domain\Time;

use App\Models\TimesheetPeriod;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Semana ISO de la hoja semanal (lunes a domingo, SPEC §3). Gemelo de resources/js/lib/week.ts:
 * `/horas?semana=2026-W39`. Trabaja con fechas locales (Europe/Madrid) sin hora.
 */
final readonly class Week
{
    private function __construct(
        public CarbonImmutable $start,
    ) {}

    public static function containing(CarbonInterface|string $date): self
    {
        return new self(TimesheetPeriod::weekStartOf($date));
    }

    /**
     * La semana actual en Madrid.
     */
    public static function current(): self
    {
        return self::containing(LocalTime::todayString());
    }

    /**
     * "2026-W39" → semana; null si el texto no es una semana ISO válida.
     */
    public static function fromIso(?string $value): ?self
    {
        if ($value === null || preg_match('/^(\d{4})-W(\d{2})$/', $value, $match) !== 1) {
            return null;
        }

        $year = (int) $match[1];
        $number = (int) $match[2];

        if ($number < 1 || $number > 53) {
            return null;
        }

        $monday = CarbonImmutable::now('UTC')->setISODate($year, $number, 1)->startOfDay();
        $week = new self(CarbonImmutable::parse($monday->toDateString()));

        return $week->iso() === $value ? $week : null;
    }

    public function iso(): string
    {
        return sprintf('%d-W%02d', $this->start->isoWeekYear, $this->start->isoWeek);
    }

    public function end(): CarbonImmutable
    {
        return $this->start->addDays(6);
    }

    public function startString(): string
    {
        return $this->start->toDateString();
    }

    public function endString(): string
    {
        return $this->end()->toDateString();
    }

    public function previous(): self
    {
        return new self($this->start->subWeek());
    }

    public function next(): self
    {
        return new self($this->start->addWeek());
    }

    /**
     * Los 7 días (Y-m-d), lunes primero.
     *
     * @return list<string>
     */
    public function days(): array
    {
        return array_map(fn (int $offset): string => $this->start->addDays($offset)->toDateString(), range(0, 6));
    }

    public function contains(CarbonInterface|string $date): bool
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $day >= $this->startString() && $day <= $this->endString();
    }

    /**
     * «21/09/2026»: el lunes, como lo muestran los mensajes (time.errors.week_closed).
     */
    public function label(): string
    {
        return $this->start->format('d/m/Y');
    }
}
