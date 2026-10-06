<?php

namespace Database\Factories;

use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Líneas del plan del día para los tests. La cabecera (DayPlan) de la persona y el día se crea si
 * falta, como hace DayPlanWriter.
 *
 * @extends Factory<DayPlanItem>
 */
class DayPlanItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->employee(),
            'date' => LocalTime::todayString(),
            'day_plan_id' => fn (array $attributes): int => DayPlan::query()->firstOrCreate(
                ['user_id' => $attributes['user_id'], 'date' => $attributes['date']],
                ['published_at' => now()],
            )->id,
            'position' => 0,
            'text' => fake()->sentence(4),
            'planned_minutes' => null,
            'status' => DayPlanItemStatus::Pending,
            'origin' => DayPlanItemOrigin::Manual,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DayPlanItemStatus::Done, 'status_changed_at' => now()]);
    }

    public function notDone(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => ['status' => DayPlanItemStatus::NotDone, 'status_changed_at' => now(), 'not_done_reason' => $reason]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => ['date' => $date]);
    }
}
