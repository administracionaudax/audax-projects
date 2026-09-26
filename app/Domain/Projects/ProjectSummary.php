<?php

namespace App\Domain\Projects;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cifras del resumen del proyecto (SPEC §6): horas estimadas frente a reales y tareas.
 * - Estimadas: suma de la estimación efectiva de las tareas raíz (la de un padre con subtareas
 *   estimadas es la suma de estas, D-037). El presupuesto va aparte (plan de la Fase 1). Se suma
 *   en la base de datos, sin cargar las tareas (SPEC §3: agregados, nunca fila a fila en PHP):
 *   las subtareas estimadas de tareas raíz vivas + las raíces sin subtareas estimadas.
 * - Reales: suma de minutos de todas las entradas del proyecto, en cualquier estado.
 */
final class ProjectSummary
{
    /**
     * @return array{estimated_minutes: int, logged_minutes: int, budget_minutes: int|null, open_tasks: int, total_tasks: int}
     */
    public function for(Project $project): array
    {
        $fromSubtasks = (int) Task::query()
            ->where('project_id', $project->id)
            ->whereNotNull('parent_task_id')
            ->whereNotNull('estimated_minutes')
            ->whereHas('parent', fn (Builder $parent) => $parent->where('project_id', $project->id)->whereNull('parent_task_id'))
            ->sum('estimated_minutes');

        $fromRoots = (int) Task::query()
            ->where('project_id', $project->id)
            ->roots()
            ->whereNotNull('estimated_minutes')
            ->whereDoesntHave('subtasks', fn (Builder $subtasks) => $subtasks->whereNotNull('estimated_minutes'))
            ->sum('estimated_minutes');

        $estimated = $fromSubtasks + $fromRoots;

        $logged = (int) TimeEntry::query()->where('project_id', $project->id)->sum('minutes');

        $counts = Task::query()
            ->where('project_id', $project->id)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS open')
            ->toBase()
            ->first();

        return [
            'estimated_minutes' => $estimated,
            'logged_minutes' => $logged,
            'budget_minutes' => $project->budget_minutes,
            'open_tasks' => (int) ($counts->open ?? 0),
            'total_tasks' => (int) ($counts->total ?? 0),
        ];
    }
}
