<?php

namespace App\Http\Resources\HourBanks;

use App\Http\Resources\HourBankResource;
use App\Models\HourBank;
use Illuminate\Http\Request;

/**
 * Bolsa en tarjetas y listados: HourBankResource más las horas comprometidas, las tareas abiertas
 * (las que se ofrecen mover al renovar) y, si se cargan, el proyecto, la bolsa anterior y la
 * renovación. Contrato: resources/js/types/hour-banks.ts (HourBankCard).
 *
 * @mixin HourBank
 */
class HourBankCardResource extends HourBankResource
{
    /**
     * @param  array{committed_minutes: int, open_tasks_count: int}|null  $commitment
     */
    public function __construct(HourBank $resource, private readonly ?array $commitment = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'committed_minutes' => $this->commitment['committed_minutes'] ?? 0,
            'open_tasks_count' => $this->commitment['open_tasks_count'] ?? 0,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'color' => $this->project->color,
                'client' => $this->project->relationLoaded('client') && $this->project->client !== null ? [
                    'id' => $this->project->client->id,
                    'name' => $this->project->client->name,
                ] : null,
            ]),
            'renewed_from' => $this->whenLoaded('renewedFrom', fn () => $this->renewedFrom === null ? null : [
                'id' => $this->renewedFrom->id,
                'name' => $this->renewedFrom->name,
                'status' => $this->renewedFrom->status->value,
            ]),
            'renewal' => $this->whenLoaded('renewal', fn () => $this->renewal === null ? null : [
                'id' => $this->renewal->id,
                'name' => $this->renewal->name,
                'status' => $this->renewal->status->value,
            ]),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer === null ? null : [
                'id' => $this->closer->id,
                'name' => $this->closer->name,
            ]),
        ];
    }
}
