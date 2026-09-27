<?php

namespace App\Domain\Gantt;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Gantt multiproyecto (/gantt, SPEC §6.1, D-060): los proyectos filtrados, agrupados y con sus
 * tareas y dependencias. Como mucho MAX_PROJECTS proyectos o MAX_TASKS tareas por vista: si se
 * superan, no se cargan las tareas y la página pide filtrar (con dos recuentos baratos).
 *
 * Contrato: resources/js/components/gantt/types.ts (GanttPortfolioData).
 */
final class GanttPortfolio
{
    public const int MAX_PROJECTS = 60;

    public const int MAX_TASKS = 1500;

    private const array USER_COLUMNS = ['id', 'name'];

    public function __construct(
        private readonly GanttFilters $filters,
        private readonly GanttData $data,
        private readonly GanttAccess $access,
    ) {}

    /**
     * Estados de tarea para la leyenda (la misma consulta que usan las tareas).
     *
     * @return list<array{id: int, name: string, color: string, category: string}>
     */
    public function statuses(): array
    {
        return $this->data->statuses();
    }

    /**
     * @param  array{cliente: int|null, departamento: int|null, responsable: int|null, estado: string}  $filters
     * @return array{
     *     limit: array{exceeded: 'projects'|'tasks'|null, projects: int, tasks: int, max_projects: int, max_tasks: int},
     *     projects: list<array<string, mixed>>,
     *     tasks: list<array<string, mixed>>,
     *     dependencies: list<array{id: int, predecessor_task_id: int, successor_task_id: int, type: string}>,
     *     range: array{start: string, end: string}
     * }
     */
    public function build(User $user, array $filters): array
    {
        $today = LocalTime::today();
        $query = Project::query();
        $this->filters->apply($query, $filters);

        $projectCount = (clone $query)->count();
        $taskCount = $projectCount === 0 || $projectCount > self::MAX_PROJECTS
            ? 0
            : Task::query()->whereIn('project_id', (clone $query)->select('projects.id'))->count();

        $exceeded = match (true) {
            $projectCount > self::MAX_PROJECTS => 'projects',
            $taskCount > self::MAX_TASKS => 'tasks',
            default => null,
        };

        $limit = [
            'exceeded' => $exceeded,
            'projects' => $projectCount,
            'tasks' => $taskCount,
            'max_projects' => self::MAX_PROJECTS,
            'max_tasks' => self::MAX_TASKS,
        ];

        if ($exceeded !== null || $projectCount === 0) {
            return [
                'limit' => $limit,
                'projects' => [],
                'tasks' => [],
                'dependencies' => [],
                'range' => $this->data->range([], [], $today),
            ];
        }

        $projects = $query
            ->with(['client:id,name', 'owner' => fn ($owner) => $owner->select(self::USER_COLUMNS)])
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->get(['projects.id', 'projects.code', 'projects.name', 'projects.color', 'projects.status', 'projects.billing_type', 'projects.client_id', 'projects.owner_user_id', 'projects.start_date', 'projects.due_date']);

        $ids = array_values(array_map(fn ($id): int => (int) $id, $projects->modelKeys()));
        $editable = $this->access->editable($user, $ids);
        $tasks = $this->data->tasks($ids, $editable);

        $projectDates = [];
        $rows = [];
        foreach ($projects as $project) {
            $projectDates[] = $project->start_date;
            $projectDates[] = $project->due_date;
            $rows[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'status' => $project->status->value,
                'uses_hour_banks' => $project->usesHourBanks(),
                'client' => $project->client === null ? null : ['id' => $project->client->id, 'name' => $project->client->name],
                'owner' => ['id' => $project->owner->id, 'name' => $project->owner->name],
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'can' => ['update' => $editable[$project->id] ?? false],
            ];
        }

        return [
            'limit' => $limit,
            'projects' => $rows,
            'tasks' => $tasks,
            'dependencies' => $this->data->dependencies($ids),
            'range' => $this->data->range($tasks, $projectDates, $today),
        ];
    }
}
