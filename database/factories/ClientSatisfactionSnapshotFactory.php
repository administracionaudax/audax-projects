<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\WeeklyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientSatisfactionSnapshot>
 */
class ClientSatisfactionSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'weekly_cycle_id' => WeeklyCycle::factory(),
            'score' => 52,
            'previous_score' => 50,
            'requested_delta' => 3,
            'delta' => 2,
            'rule' => 'dampened',
            'reasoning' => 'Entrega validada por el cliente.',
            'evidence_level' => 'LOW',
            'explicit_client_impact' => true,
            'confidence' => '0.800',
        ];
    }
}
