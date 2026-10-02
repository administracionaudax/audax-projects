<?php

namespace Database\Factories;

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absence>
 */
class AbsenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AbsenceType::Vacation,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'partial_minutes' => null,
            'status' => AbsenceStatus::Requested,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AbsenceStatus::Approved, 'reviewed_at' => now()]);
    }

    public function between(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => ['start_date' => $from, 'end_date' => $to]);
    }

    public function partial(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['partial_minutes' => $minutes]);
    }
}
