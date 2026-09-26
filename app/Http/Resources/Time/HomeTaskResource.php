<?php

namespace App\Http\Resources\Time;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarea en las tarjetas de Inicio (SPEC §5.1). Contrato: resources/js/types/time.ts (HomeTask).
 * Cargar project y status antes (N+1).
 *
 * @mixin Task
 */
class HomeTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'project' => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'color' => $this->project->color,
            ],
            'status' => [
                'name' => $this->status->name,
                'color' => $this->status->color,
                'is_done' => $this->status->isDone(),
            ],
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'is_milestone' => $this->is_milestone,
        ];
    }
}
