<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\TaskTypeResource;
use App\Models\TaskType;
use Illuminate\Http\Request;

/**
 * Tipo de tarea en /admin/tipos-de-tarea: TaskTypeResource más su uso. Contrato:
 * resources/js/types/admin.ts (AdminTaskType). Cargar tasks_count (withCount, incluidas las
 * borradas: un tipo con tareas se desactiva, no se borra).
 *
 * @mixin TaskType
 */
class TaskTypeRowResource extends TaskTypeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tasks = (int) $this->resource->getAttribute('tasks_count');

        return [
            ...parent::toArray($request),
            'tasks_count' => $tasks,
            'in_use' => $tasks > 0,
        ];
    }
}
