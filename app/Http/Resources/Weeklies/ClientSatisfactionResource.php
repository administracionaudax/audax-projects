<?php

namespace App\Http\Resources\Weeklies;

use App\Models\ClientSatisfactionSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satisfacción de un cliente al cerrar una semana (F-132, gráfica y tendencias). Contrato:
 * weeklies.ts (ClientSatisfactionPoint).
 *
 * @mixin ClientSatisfactionSnapshot
 */
class ClientSatisfactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'client_id' => $this->client_id,
            'weekly_cycle_id' => $this->weekly_cycle_id,
            'score' => $this->score,
            'previous_score' => $this->previous_score,
            'delta' => $this->delta,
            'rule' => $this->rule,
            'reasoning' => $this->reasoning,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
