<?php

namespace Database\Factories;

use App\Enums\WeeklyExemptionReason;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyExemption>
 */
class WeeklyExemptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekly_cycle_id' => WeeklyCycle::factory(),
            'user_id' => User::factory()->employee(),
            'reason' => WeeklyExemptionReason::Manual,
            'absence_id' => null,
            'note' => null,
        ];
    }

    public function waived(): static
    {
        return $this->state(fn (array $attributes) => ['reason' => WeeklyExemptionReason::Waived]);
    }

    public function absence(?int $absenceId = null): static
    {
        return $this->state(fn (array $attributes) => ['reason' => WeeklyExemptionReason::Absence, 'absence_id' => $absenceId]);
    }
}
