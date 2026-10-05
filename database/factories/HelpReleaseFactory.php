<?php

namespace Database\Factories;

use App\Models\HelpRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpRelease>
 */
class HelpReleaseFactory extends Factory
{
    private static int $week = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = self::$week++;

        return [
            'major_version' => 1 + intdiv($n, 60),
            'month_number' => 1 + intdiv($n % 60, 5),
            'week_of_month' => 1 + $n % 5,
            'summary' => fake()->sentence(),
            'is_hidden' => false,
        ];
    }
}
