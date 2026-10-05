<?php

namespace Database\Factories;

use App\Enums\WeeklyEntrySource;
use App\Models\Client;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyEntry>
 */
class WeeklyEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekly_submission_id' => WeeklySubmission::factory(),
            'client_id' => Client::factory(),
            'project_id' => null,
            'body' => fake()->paragraph(),
            'source' => WeeklyEntrySource::Text,
            'position' => 0,
        ];
    }

    /** Apunte «General / Interno» (sin cliente). */
    public function general(): static
    {
        return $this->state(fn (array $attributes) => ['client_id' => null]);
    }
}
