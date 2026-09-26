<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato: resources/js/types/domain.ts (Task). Cargar assignee antes (N+1). Los campos
 * opcionales solo aparecen si el controlador los carga: description (si se selecciona la columna),
 * logged_minutes (withSum('timeEntries', 'minutes')) y *_count (withCount).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'hour_bank_id' => $this->hour_bank_id,
            'parent_task_id' => $this->parent_task_id,
            'title' => $this->title,
            // Solo si se ha seleccionado la columna: los listados la omiten para aligerar la página.
            'description' => $this->when(array_key_exists('description', $this->resource->getAttributes()), fn () => $this->description),
            'task_type_id' => $this->task_type_id,
            'status_id' => $this->status_id,
            'priority' => $this->priority->value,
            'assignee' => UserSummaryResource::make($this->whenLoaded('assignee')),
            'assignee_user_id' => $this->assignee_user_id,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'estimated_minutes' => $this->estimated_minutes,
            'is_billable' => $this->is_billable,
            'is_milestone' => $this->is_milestone,
            'is_completed' => $this->isCompleted(),
            'position' => $this->position,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'logged_minutes' => $this->whenAggregated('timeEntries', 'minutes', 'sum', fn ($value) => (int) $value),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'comments_count' => $this->whenCounted('comments'),
            'attachments_count' => $this->whenCounted('attachments'),
        ];
    }
}
