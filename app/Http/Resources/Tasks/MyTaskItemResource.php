<?php

namespace App\Http\Resources\Tasks;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Tarea de Mis tareas (contrato: resources/js/types/tasks.ts, MyTaskItem): TaskResource con su
 * proyecto, su bolsa y su tarea padre. Cargar antes project, hourBank y parent (sin N+1).
 *
 * @mixin Task
 */
class MyTaskItemResource extends TaskResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;

        return [
            ...parent::toArray($request),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $task->project->id,
                'code' => $task->project->code,
                'name' => $task->project->name,
                'color' => $task->project->color,
            ]),
            'hour_bank' => $this->whenLoaded('hourBank', fn () => $task->hourBank === null ? null : [
                'id' => $task->hourBank->id,
                'name' => $task->hourBank->name,
            ]),
            'parent' => $this->whenLoaded('parent', fn () => $task->parent === null ? null : [
                'id' => $task->parent->id,
                'title' => $task->parent->title,
            ]),
        ];
    }
}
