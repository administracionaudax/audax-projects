<?php

namespace Database\Factories;

use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Models\AiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'provider' => AiProvider::Gemini,
            'model' => 'gemini-2.5-flash',
            'feature' => AiFeature::WeeklyReport,
            'operation' => 'generateContent',
            'status' => AiUsage::STATUS_SUCCESS,
            'latency_ms' => 1200,
            'prompt_tokens' => 1000,
            'response_tokens' => 200,
            'total_tokens' => 1200,
            'estimated_cost_usd' => '0.000800',
        ];
    }

    public function failed(string $error = 'HTTP 503'): static
    {
        return $this->state(fn (array $attributes) => ['status' => AiUsage::STATUS_ERROR, 'error' => $error]);
    }
}
