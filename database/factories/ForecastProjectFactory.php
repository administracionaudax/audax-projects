<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectColors;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastStatus;
use App\Models\Client;
use App\Models\ForecastProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Proyectos previstos para los tests y los datos de ejemplo (D-280): por defecto, abierto y
 * «posible», con un cliente que aún no existe (nombre libre).
 *
 * @extends Factory<ForecastProject>
 */
class ForecastProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Previsto '.fake()->unique()->city(),
            'client_id' => null,
            'prospect_name' => fake()->company(),
            'color' => fake()->randomElement(ProjectColors::PALETTE),
            'description' => null,
            'owner_user_id' => User::factory()->departmentManager(),
            'confidence' => ForecastConfidence::Tentative,
            'status' => ForecastStatus::Open,
            'start_date' => null,
            'end_date' => null,
            'estimated_minutes' => null,
        ];
    }

    public function firm(): static
    {
        return $this->state(fn (array $attributes) => ['confidence' => ForecastConfidence::Firm]);
    }

    public function tentative(): static
    {
        return $this->state(fn (array $attributes) => ['confidence' => ForecastConfidence::Tentative]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['confidence' => ForecastConfidence::Firm, 'status' => ForecastStatus::Confirmed]);
    }

    public function lost(string $reason = 'Precio'): static
    {
        return $this->state(fn (array $attributes) => ['status' => ForecastStatus::Lost, 'lost_reason' => $reason, 'lost_at' => now()]);
    }

    public function forClient(?Client $client = null): static
    {
        return $this->state(fn (array $attributes) => ['client_id' => $client->id ?? Client::factory(), 'prospect_name' => null]);
    }

    public function between(string $from, ?string $to): static
    {
        return $this->state(fn (array $attributes) => ['start_date' => $from, 'end_date' => $to]);
    }
}
