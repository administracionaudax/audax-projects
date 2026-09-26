<?php

namespace App\Http\Resources;

use App\Models\TaskType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato: resources/js/types/domain.ts (TaskType).
 *
 * @mixin TaskType
 */
class TaskTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'department_id' => $this->department_id,
            'is_billable_default' => $this->is_billable_default,
            'is_active' => $this->is_active,
            'position' => $this->position,
        ];
    }
}
