<?php

namespace Database\Factories;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetPeriod>
 */
class TimesheetPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => TimesheetPeriod::weekStartOf(now())->toDateString(),
            'status' => TimesheetStatus::Open,
        ];
    }

    public function status(TimesheetStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'submitted_at' => $status === TimesheetStatus::Open ? null : now(),
        ]);
    }

    public function week(string $anyDate): static
    {
        return $this->state(fn (array $attributes) => ['week_start' => TimesheetPeriod::weekStartOf($anyDate)->toDateString()]);
    }
}
