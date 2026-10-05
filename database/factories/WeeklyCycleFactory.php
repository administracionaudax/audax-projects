<?php

namespace Database\Factories;

use App\Domain\Weeklies\WeeklyCalendar;
use App\Enums\WeeklyCycleStatus;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto, semanas CERRADAS cada vez más antiguas desde la del 28/09/2026 (puede haber muchas a
 * la vez). active() da la semana del 05/10/2026 (solo puede haber una activa); forWeekOf() fija la
 * semana de cualquier fecha.
 *
 * @extends Factory<WeeklyCycle>
 */
class WeeklyCycleFactory extends Factory
{
    private static int $offset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monday = CarbonImmutable::parse('2026-09-28')->subWeeks(self::$offset++);
        $period = (new WeeklyCalendar)->periodFor($monday->toDateString());

        return [
            ...$period->toAttributes(),
            'status' => WeeklyCycleStatus::Closed,
            'closed_at' => CarbonImmutable::parse($period->end->toDateString().' 18:00:00', WeeklyCalendar::TIMEZONE)->utc(),
        ];
    }

    /** La semana (de lunes a viernes) que contiene esa fecha. */
    public function forWeekOf(string $date): static
    {
        return $this->state(fn (array $attributes) => (new WeeklyCalendar)->periodFor($date)->toAttributes());
    }

    /** Semana activa (por defecto, la del 05/10/2026). */
    public function active(string $date = '2026-10-05'): static
    {
        return $this->forWeekOf($date)->state(fn (array $attributes) => [
            'status' => WeeklyCycleStatus::Active,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WeeklyCycleStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function withDeadline(string $date): static
    {
        return $this->state(fn (array $attributes) => ['deadline_date' => $date]);
    }
}
