<?php

namespace Database\Factories;

use App\Models\DayPlan;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DayPlan>
 */
class DayPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->employee(),
            'date' => LocalTime::todayString(),
            'published_at' => null,
            'note' => null,
        ];
    }
}
