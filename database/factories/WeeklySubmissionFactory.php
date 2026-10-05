<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto, un BORRADOR (submitted_at nulo). submitted() lo da por enviado.
 *
 * @extends Factory<WeeklySubmission>
 */
class WeeklySubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekly_cycle_id' => WeeklyCycle::factory(),
            'user_id' => User::factory()->employee(),
            'submitted_at' => null,
            'draft_saved_at' => now(),
        ];
    }

    public function submitted(?string $at = null): static
    {
        return $this->state(fn (array $attributes) => ['submitted_at' => $at ?? now()]);
    }
}
