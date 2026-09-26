<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\TaskStatusResource;
use App\Models\TaskStatus;
use Illuminate\Http\Request;

/**
 * Estado de tarea en /admin/estados: TaskStatusResource más cuántas tareas lo usan. Contrato:
 * resources/js/types/admin.ts (AdminTaskStatus).
 *
 * @mixin TaskStatus
 */
class TaskStatusRowResource extends TaskStatusResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'tasks_count' => (int) $this->resource->getAttribute('tasks_count'),
        ];
    }
}
