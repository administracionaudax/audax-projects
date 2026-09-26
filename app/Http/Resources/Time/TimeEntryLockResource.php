<?php

namespace App\Http\Resources\Time;

use App\Http\Resources\UserSummaryResource;
use App\Models\TimeEntryLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bloqueo de horas (D-034). Contrato: resources/js/types/time.ts (TimeLockData).
 * Cargar client, project y locker antes (N+1); `unlocker` lo añade el controlador.
 *
 * @mixin TimeEntryLock
 */
class TimeEntryLockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => $this->client === null ? null : ['id' => $this->client->id, 'name' => $this->client->name],
            'project' => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ],
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'reference' => $this->reference,
            'entries_count' => $this->entries_count,
            'locked_by' => UserSummaryResource::make($this->whenLoaded('locker')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'unlocked_at' => $this->unlocked_at?->toIso8601ZuluString(),
        ];
    }
}
