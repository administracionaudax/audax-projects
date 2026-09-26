<?php

namespace App\Http\Resources;

use App\Models\TaskStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato: resources/js/types/domain.ts (TaskStatus).
 *
 * @mixin TaskStatus
 */
class TaskStatusResource extends JsonResource
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
            'category' => $this->category->value,
            'position' => $this->position,
            'is_default' => $this->is_default,
        ];
    }
}
