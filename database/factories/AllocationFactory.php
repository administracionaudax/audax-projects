<?php

namespace Database\Factories;

use App\Enums\AllocationMode;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Asignaciones para los tests y los datos de ejemplo (D-282). Por defecto, 40 h en total de un
 * empleado en un proyecto real durante octubre de 2026. Los estados eligen el contenedor (real o
 * previsto), quién (persona o hueco) y el modo.
 *
 * @extends Factory<Allocation>
 */
class AllocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'forecast_project_id' => null,
            'user_id' => User::factory()->employee(),
            'department_id' => null,
            'mode' => AllocationMode::Total,
            'minutes' => 40 * 60,
            'percent' => null,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => ['project_id' => $project->id, 'forecast_project_id' => null]);
    }

    public function forForecast(?ForecastProject $forecast = null): static
    {
        return $this->state(fn (array $attributes) => ['project_id' => null, 'forecast_project_id' => $forecast->id ?? ForecastProject::factory()]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $user->id, 'department_id' => null]);
    }

    /** Un hueco: el departamento, sin persona. */
    public function gap(Department $department): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null, 'department_id' => $department->id]);
    }

    public function total(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['mode' => AllocationMode::Total, 'minutes' => $minutes, 'percent' => null]);
    }

    public function perDay(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['mode' => AllocationMode::PerDay, 'minutes' => $minutes, 'percent' => null]);
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (array $attributes) => ['mode' => AllocationMode::Percent, 'minutes' => null, 'percent' => $percent]);
    }

    public function monthly(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['mode' => AllocationMode::Monthly, 'minutes' => $minutes, 'percent' => null]);
    }

    public function between(string $from, ?string $to): static
    {
        return $this->state(fn (array $attributes) => ['start_date' => $from, 'end_date' => $to]);
    }
}
