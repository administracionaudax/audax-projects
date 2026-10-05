<?php

namespace App\Http\Resources\Weeklies;

use App\Models\WeeklyExemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exención de una persona en una semana. Contrato: weeklies.ts (WeeklyExemption). El tipo de la
 * ausencia nunca va aquí (dato de salud, D-088): solo que es por ausencia.
 *
 * @mixin WeeklyExemption
 */
class WeeklyExemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekly_cycle_id' => $this->weekly_cycle_id,
            'user_id' => $this->user_id,
            'reason' => $this->reason->value,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
