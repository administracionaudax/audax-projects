<?php

namespace App\Http\Resources\Weeklies;

use App\Http\Resources\UserSummaryResource;
use App\Models\AiUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una llamada a la IA en la página «Uso de IA» (F-173 y F-180). Coste en USD como texto decimal.
 * Contrato: weeklies.ts (AiUsageRow).
 *
 * @mixin AiUsage
 */
class AiUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'model' => $this->model,
            'feature' => $this->feature->value,
            'operation' => $this->operation,
            'status' => $this->status,
            'latency_ms' => $this->latency_ms,
            'prompt_tokens' => $this->prompt_tokens,
            'response_tokens' => $this->response_tokens,
            'total_tokens' => $this->total_tokens,
            'character_count' => $this->character_count,
            'estimated_cost_usd' => $this->estimated_cost_usd,
            'error' => $this->error,
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
