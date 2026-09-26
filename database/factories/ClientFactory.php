<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'tax_id' => fake()->optional()->bothify('B########'),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('6## ### ###'),
            'notes' => null,
            'is_active' => true,
            'default_hourly_rate' => fake()->optional()->randomElement(['45.00', '55.00', '65.00']),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
