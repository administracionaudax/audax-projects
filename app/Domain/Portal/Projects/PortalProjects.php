<?php

namespace App\Domain\Portal\Projects;

use App\Domain\Gantt\GanttData;
use App\Domain\Portal\PortalScope;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Proyectos en el portal (SPEC §11, D-064). Todo sale de PortalScope:
 * - solo los proyectos de SU cliente que el equipo ha abierto al portal (vista del proyecto y, aparte,
 *   el Gantt),
 * - de cada tarea: título, estado, fechas, si es hito, si está completada y sus subtareas. NUNCA
 *   descripciones, comentarios, adjuntos, responsables, estimaciones ni datos económicos,
 * - horas totales por tarea solo si el proyecto las enseña (canViewTaskHours) y solo las horas que ve
 *   el cliente (PortalScope::entries: aprobadas y bloqueadas, o también enviadas). Nunca por persona.
 *
 * Contrato: resources/js/components/portal/projects/types.ts.
 */
final class PortalProjects
{
    /** Columnas de la tarea que puede ver el cliente. */
    private const array TASK_COLUMNS = [
        'id', 'project_id', 'parent_task_id', 'title', 'status_id', 'start_date', 'due_date',
        'is_milestone', 'completed_at', 'position',
    ];

    public function __construct(private readonly GanttData $gantt) {}

    /**
     * Proyectos abiertos al portal (la vista del proyecto, el Gantt o los dos), por nombre, con el
     * avance (tareas completadas / total) de los que tienen la vista abierta.
     *
     * @return list<array{id: int, code: string, name: string, status: string, start_date: string|null, due_date: string|null, view: bool, gantt: bool, progress: array{done: int, total: int}|null}>
     */
    public function open(PortalScope $scope): array
    {
        $projects = $this->openQuery($scope)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'status', 'start_date', 'due_date', 'portal_project_visible', 'portal_gantt_visible']);

        $visible = $projects->filter(fn (Project $project): bool => $project->portal_project_visible)->modelKeys();
        $progress = $visible === [] ? collect() : Task::query()
            ->whereIn('project_id', $visible)
            ->selectRaw('project_id, count(*) as total, count(completed_at) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $rows = [];
        foreach ($projects as $project) {
            $counts = $progress->get($project->id);

            $rows[] = [
                ...$this->summary($project),
                'view' => $project->portal_project_visible,
                'gantt' => $project->portal_gantt_visible,
                'progress' => $project->portal_project_visible ? [
                    'done' => (int) ($counts?->getAttribute('done') ?? 0),
                    'total' => (int) ($counts?->getAttribute('total') ?? 0),
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * Navegación del portal: los proyectos abiertos (nombre y adónde lleva el enlace).
     *
     * @return list<array{id: int, code: string, name: string, view: bool, gantt: bool}>
     */
    public function navigation(PortalScope $scope): array
    {
        $rows = [];
        foreach ($this->openQuery($scope)->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'portal_project_visible', 'portal_gantt_visible']) as $project) {
            $rows[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'view' => $project->portal_project_visible,
                'gantt' => $project->portal_gantt_visible,
            ];
        }

        return $rows;
    }

    /**
     * Vista del proyecto: sus tareas (raíz y, detrás de cada una, sus subtareas), el resumen por
     * estado y, si se enseñan, las horas por tarea.
     *
     * @return array<string, mixed>
     */
    public function show(PortalScope $scope, Project $project): array
    {
        $showHours = $scope->canViewTaskHours($project);
        $tasks = $this->tasks($project);
        $statuses = $this->statusesById();
        $ordered = $this->ordered($tasks);
        $minutes = $showHours ? $this->visibleMinutes($scope, $tasks) : [];

        // Horas de una tarea con subtareas: las suyas más las de sus subtareas (una sola vez en el total).
        $totals = [];
        foreach ($ordered as [$task, $depth]) {
            $own = $minutes[$task->id] ?? 0;
            $totals[$task->id] = ($totals[$task->id] ?? 0) + $own;

            if ($depth === 1 && $task->parent_task_id !== null) {
                $totals[$task->parent_task_id] = ($totals[$task->parent_task_id] ?? 0) + $own;
            }
        }

        $children = [];
        foreach ($ordered as [$task, $depth]) {
            if ($depth === 1 && $task->parent_task_id !== null) {
                $children[$task->parent_task_id] = ($children[$task->parent_task_id] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($ordered as [$task, $depth]) {
            $row = [
                'id' => $task->id,
                'parent_task_id' => $depth === 1 ? $task->parent_task_id : null,
                'depth' => $depth,
                'title' => $task->title,
                'status' => $statuses[$task->status_id] ?? null,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'is_milestone' => $task->is_milestone,
                'is_completed' => $task->completed_at !== null,
                'subtasks_count' => $children[$task->id] ?? 0,
            ];

            if ($showHours) {
                $row['minutes'] = $totals[$task->id] ?? 0;
            }

            $rows[] = $row;
        }

        $done = count(array_filter($rows, fn (array $row): bool => $row['is_completed']));

        return [
            'project' => $this->summary($project),
            'tasks' => $rows,
            'statuses' => array_values($statuses),
            'showHours' => $showHours,
            'totals' => [
                'tasks' => count($rows),
                'done' => $done,
                'open' => count($rows) - $done,
                'milestones' => count(array_filter($rows, fn (array $row): bool => $row['is_milestone'])),
                'minutes' => $showHours ? array_sum($minutes) : null,
            ],
            'gantt' => $scope->canViewGantt($project),
        ];
    }

    /**
     * Gantt de solo lectura: las tareas con la forma de GanttTask, pero sin responsables, sin
     * estimaciones ni horas y sin permiso de edición.
     *
     * @return array{project: array<string, mixed>, tasks: list<array<string, mixed>>, dependencies: list<array{id: int, predecessor_task_id: int, successor_task_id: int, type: string}>, statuses: list<array{id: int, name: string, color: string, category: string}>, range: array{start: string, end: string}, view: bool}
     */
    public function gantt(PortalScope $scope, Project $project, CarbonImmutable $today): array
    {
        $tasks = $this->tasks($project);
        $statuses = $this->statusesById();
        $ids = array_flip($tasks->modelKeys());

        $subtasks = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null && isset($ids[$task->parent_task_id])) {
                $subtasks[$task->parent_task_id] = ($subtasks[$task->parent_task_id] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'parent_task_id' => $task->parent_task_id !== null && isset($ids[$task->parent_task_id]) ? $task->parent_task_id : null,
                'title' => $task->title,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'is_milestone' => $task->is_milestone,
                'is_completed' => $task->completed_at !== null,
                'status' => $statuses[$task->status_id] ?? null,
                'assignee' => null,
                'estimated_minutes' => null,
                'logged_minutes' => 0,
                'subtasks_count' => $subtasks[$task->id] ?? 0,
                'can' => ['update' => false],
            ];
        }

        return [
            'project' => $this->summary($project),
            'tasks' => $rows,
            'dependencies' => $this->gantt->dependencies([$project->id]),
            'statuses' => array_values($statuses),
            'range' => $this->gantt->range($rows, [$project->start_date, $project->due_date], $today),
            'view' => $scope->canViewProject($project),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string, status: string, start_date: string|null, due_date: string|null}
     */
    public function summary(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status->value,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
        ];
    }

    /**
     * @return Builder<Project>
     */
    private function openQuery(PortalScope $scope): Builder
    {
        return $scope->projects()->where(fn (Builder $open) => $open
            ->where('portal_project_visible', true)
            ->orWhere('portal_gantt_visible', true));
    }

    /**
     * @return Collection<int, Task>
     */
    private function tasks(Project $project): Collection
    {
        return Task::query()
            ->select(self::TASK_COLUMNS)
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tareas raíz en su orden y, detrás de cada una, sus subtareas. Una subtarea cuya tarea padre no
     * está (en la papelera) sale como raíz.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{0: Task, 1: int}>
     */
    private function ordered(Collection $tasks): array
    {
        $ids = array_flip($tasks->modelKeys());
        $children = [];
        $roots = [];

        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null && isset($ids[$task->parent_task_id])) {
                $children[$task->parent_task_id][] = $task;
            } else {
                $roots[] = $task;
            }
        }

        $ordered = [];
        foreach ($roots as $root) {
            $ordered[] = [$root, 0];

            foreach ($children[$root->id] ?? [] as $child) {
                $ordered[] = [$child, 1];
            }
        }

        return $ordered;
    }

    /**
     * Minutos visibles para el cliente de cada tarea (sus estados de horas), en una consulta.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, int>
     */
    private function visibleMinutes(PortalScope $scope, Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }

        $minutes = [];
        foreach ($scope->entries()
            ->whereIn('task_id', $tasks->modelKeys())
            ->selectRaw('task_id, sum(minutes) as minutes')
            ->groupBy('task_id')
            ->toBase()
            ->get() as $row) {
            $minutes[(int) $row->task_id] = (int) $row->minutes;
        }

        return $minutes;
    }

    /**
     * @return array<int, array{id: int, name: string, color: string, category: string}>
     */
    private function statusesById(): array
    {
        $statuses = [];
        foreach ($this->gantt->statuses() as $status) {
            $statuses[$status['id']] = $status;
        }

        return $statuses;
    }
}
