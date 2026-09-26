<?php

namespace Database\Factories;

use App\Enums\TaskStatusCategory;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatus>
 */
class TaskStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'color' => '#56667A',
            'category' => TaskStatusCategory::Todo,
            'position' => fake()->numberBetween(10, 99),
            'is_default' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => ['category' => TaskStatusCategory::Done, 'color' => '#179FA5']);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => ['category' => TaskStatusCategory::InProgress, 'color' => '#0171FF']);
    }
}
