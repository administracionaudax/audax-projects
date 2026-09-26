<?php

namespace Database\Factories;

use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El consumo (consumed_minutes, overage_minutes) lo calcula siempre HourBankLedger a partir de las
 * entradas: no se fija en la factoría.
 *
 * @extends Factory<HourBank>
 */
class HourBankFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->hourBank(),
            'name' => 'Bolsa '.fake()->randomElement(['Desarrollo', 'Diseño', 'Marketing']).' '.fake()->numberBetween(10, 80).'h',
            'department_id' => null,
            'total_minutes' => 50 * 60,
            'hourly_rate' => null,
            'price_amount' => null,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'status' => HourBankStatus::Active,
            'overage_policy' => OveragePolicy::Inherit,
        ];
    }

    public function hours(int $hours): static
    {
        return $this->state(fn (array $attributes) => ['total_minutes' => $hours * 60]);
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(fn (array $attributes) => ['department_id' => $department->id]);
    }

    public function allowOverage(): static
    {
        return $this->state(fn (array $attributes) => ['overage_policy' => OveragePolicy::Allow]);
    }

    public function blockOverage(): static
    {
        return $this->state(fn (array $attributes) => ['overage_policy' => OveragePolicy::Block]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HourBankStatus::Closed, 'closed_at' => now()]);
    }

    public function renewed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => HourBankStatus::Renewed]);
    }
}
