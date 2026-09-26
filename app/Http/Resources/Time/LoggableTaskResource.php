<?php

namespace App\Http\Resources\Time;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarea en el buscador de imputación y en las filas de la hoja semanal.
 * Contrato: resources/js/types/time.ts (LoggableTask). Cargar project y hourBank antes (N+1).
 *
 * @mixin Task
 */
class LoggableTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->project;
        $internal = $project->isInternal();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'is_internal' => $internal,
            ],
            'hour_bank' => $this->when(
                $this->relationLoaded('hourBank'),
                fn () => $this->hourBank === null ? null : ['id' => $this->hourBank->id, 'name' => $this->hourBank->name],
            ),
            // En proyectos internos nunca es facturable (SPEC §7).
            'is_billable' => ! $internal && $this->is_billable,
            'is_milestone' => $this->is_milestone,
            'is_completed' => $this->isCompleted(),
            'is_deleted' => $this->trashed(),
        ];
    }
}
