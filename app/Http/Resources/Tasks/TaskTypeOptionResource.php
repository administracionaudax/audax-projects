<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\TaskTypeResource;
use App\Models\TaskType;
use Illuminate\Http\Request;

/**
 * Tipo de tarea en los selectores: un tipo borrado sigue apareciendo en las tareas que lo usan,
 * pero ya no se puede elegir (is_active = false).
 *
 * @mixin TaskType
 */
class TaskTypeOptionResource extends TaskTypeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_active' => $this->is_active && ! $this->trashed(),
        ];
    }
}
