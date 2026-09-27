<?php

namespace App\Domain\Reports;

use App\Models\Project;
use App\Models\Task;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tareas vencidas del alcance de un informe (SPEC §10.1 y D-047): abiertas, con fecha límite
 * anterior a hoy en Madrid (D-037) y de proyectos no archivados.
 *
 * Alcance (D-044), como la precisión de estimación de Metrics: un admin sin filtros de persona ni
 * de departamento ve todas (también las que no tienen responsable); en otro caso, las asignadas a
 * las personas del alcance (ReportScope::people(): su equipo, con los filtros aplicados). Los
 * filtros de cliente, proyecto, bolsa y tipo se aplican tal cual. El periodo no cuenta: una tarea
 * vencida lo está hoy. Añadido por R1 (dashboard de dirección).
 */
final class OverdueTasks
{
    /**
     * @return array{count: int, tasks: list<array{id: int, title: string, project_id: int,
     *     project: array{code: string, name: string, color: string}, assignee: string|null,
     *     due_date: string, days_overdue: int, is_milestone: bool}>}
     */
    public function forScope(ReportScope $scope, int $limit = 10): array
    {
        $today = LocalTime::today();
        $query = $this->query($scope, $today->toDateString());
        $count = (clone $query)->count();

        $tasks = $count === 0 ? new Collection : (clone $query)
            ->with(['project:id,code,name,color', 'assignee:id,name'])
            ->orderBy('due_date')
            ->orderBy('tasks.id')
            ->limit($limit)
            ->get(['tasks.id', 'tasks.title', 'tasks.project_id', 'tasks.assignee_user_id', 'tasks.due_date', 'tasks.is_milestone']);

        return [
            'count' => $count,
            'tasks' => array_values($tasks->map(function (Task $task) use ($today): array {
                $due = $task->due_date?->toDateString() ?? $today->toDateString();

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'project_id' => $task->project_id,
                    'project' => ['code' => $task->project->code, 'name' => $task->project->name, 'color' => $task->project->color],
                    'assignee' => $task->assignee?->name,
                    'due_date' => $due,
                    'days_overdue' => (int) CarbonImmutable::parse($due)->diffInDays(CarbonImmutable::parse($today->toDateString())),
                    'is_milestone' => $task->is_milestone,
                ];
            })->all()),
        ];
    }

    /**
     * @return Builder<Task>
     */
    private function query(ReportScope $scope, string $today): Builder
    {
        $f = $scope->filters;

        return Task::query()
            ->open()
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->when(! $scope->viewer->isAdmin() || $f->userIds !== [] || $f->departmentIds !== [],
                fn (Builder $q) => $q->whereIn('assignee_user_id', $scope->people()->modelKeys()))
            ->when($f->clientIds !== [], fn (Builder $q) => $q->whereIn('project_id', Project::query()->select('id')->whereIn('client_id', $f->clientIds)))
            ->when($f->projectIds !== [], fn (Builder $q) => $q->whereIn('project_id', $f->projectIds))
            ->when($f->bankIds !== [], fn (Builder $q) => $q->whereIn('hour_bank_id', $f->bankIds))
            ->when($f->taskTypeIds !== [], fn (Builder $q) => $q->whereIn('task_type_id', $f->taskTypeIds));
    }
}
