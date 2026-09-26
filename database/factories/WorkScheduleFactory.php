<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'valid_from' => '2020-01-01',
            'valid_to' => null,
            'mon_minutes' => 480,
            'tue_minutes' => 480,
            'wed_minutes' => 480,
            'thu_minutes' => 480,
            'fri_minutes' => 480,
            'sat_minutes' => 0,
            'sun_minutes' => 0,
        ];
    }

    /**
     * Jornada intensiva de 7 h (lunes a jueves) y 6 h el viernes.
     */
    public function intensive(): static
    {
        return $this->state(fn (array $attributes) => [
            'mon_minutes' => 420,
            'tue_minutes' => 420,
            'wed_minutes' => 420,
            'thu_minutes' => 420,
            'fri_minutes' => 360,
        ]);
    }
}
