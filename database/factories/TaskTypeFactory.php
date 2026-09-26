<?php

namespace Database\Factories;

use App\Models\TaskType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskType>
 */
class TaskTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'color' => '#0171FF',
            'icon' => null,
            'department_id' => null,
            'is_billable_default' => true,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function notBillable(): static
    {
        return $this->state(fn (array $attributes) => ['is_billable_default' => false]);
    }
}
