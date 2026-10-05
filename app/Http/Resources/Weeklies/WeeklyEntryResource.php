<?php

namespace App\Http\Resources\Weeklies;

use App\Models\WeeklyEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Apunte de una weekly por cliente (client_id nulo = «General / Interno»). Contrato: weeklies.ts
 * (WeeklyEntry).
 *
 * @mixin WeeklyEntry
 */
class WeeklyEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn (): ?array => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'icon' => $this->client->icon,
            ]),
            'project_id' => $this->project_id,
            'body' => $this->body,
            'source' => $this->source->value,
            'position' => $this->position,
        ];
    }
}
