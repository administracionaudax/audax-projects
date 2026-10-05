<?php

namespace App\Domain\Weeklies\Tasks;

use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * La pestaña «Tareas» de «Mi espacio» (F-055 a F-063, D-151 y D-203), el TaskView de WeeklySync sobre
 * las tareas de Audax:
 * - mis tareas: las asignadas a mí, de proyectos sin archivar que puedo ver; todas las pendientes y las
 *   DONE_LIMIT últimas hechas; de las más nuevas a las más antiguas, como el original,
 * - con mi archivado personal (task_archives): `archived` dice si la he ocultado de mi lista,
 * - las notas son la descripción en texto plano (TaskNotes),
 * - `can` repite TaskPolicy::update y ::delete sin una consulta por tarea.
 * Y el catálogo de proyectos en los que puedo crear tareas (con sus bolsas abiertas), para el alta y
 * para revisar las tareas sugeridas.
 */
final class MySpaceTasks
{
    /** Hechas que se enseñan (las más recientes); las pendientes van todas. */
    public const int DONE_LIMIT = 100;

    /** Tope de pendientes, por si acaso. */
    public const int OPEN_LIMIT = 500;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $archived = array_flip(array_map(intval(...), TaskArchive::query()->where('user_id', $user->id)->pluck('task_id')->all()));
        $editable = $this->editableProjects($user);

        $open = $this->query($user)->whereNull('tasks.completed_at')->limit(self::OPEN_LIMIT)->get();
        $done = $this->query($user)->whereNotNull('tasks.completed_at')->reorder()->orderByDesc('tasks.completed_at')->orderByDesc('tasks.id')->limit(self::DONE_LIMIT)->get();

        $tasks = $open->concat($done)->sortByDesc(fn (Task $task): string => sprintf('%s-%010d', $task->created_at?->format('Y-m-d H:i:s') ?? '', $task->id))->values();

        return array_values($tasks->map(fn (Task $task): array => $this->present($task, $user, isset($archived[$task->id]), $editable))->all());
    }

    /**
     * Proyectos abiertos en los que puedo crear tareas (TaskPolicy::create): todos para el admin y los
     * responsables; para los demás, aquellos de los que soy miembro o gestor. Con sus bolsas abiertas
     * (las del departamento de la persona, primero), por cliente y código.
     *
     * @return list<array{id: int, code: string, name: string, client: array{id: int, name: string, icon: string|null}|null, uses_banks: bool, banks: list<array{id: int, name: string, department_id: int|null}>}>
     */
    public function catalog(User $user): array
    {
        $editable = $this->editableProjects($user);

        $projects = Project::query()
            ->whereIn('status', array_map(fn (ProjectStatus $status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES))
            ->when($editable !== null, fn (Builder $query) => $query->whereKey($editable === null ? [] : array_keys($editable)))
            ->with('client:id,name,icon')
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'client_id', 'billing_type']);

        $bankProjects = array_values(array_map(intval(...), $projects->filter(fn (Project $project): bool => $project->usesHourBanks())->modelKeys()));
        $banks = $bankProjects === [] ? new Collection : HourBank::query()
            ->whereIn('project_id', $bankProjects)
            ->whereIn('status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'department_id', 'start_date']);

        $byProject = [];
        foreach ($banks as $bank) {
            $byProject[$bank->project_id][] = $bank;
        }

        $result = [];
        foreach ($projects as $project) {
            $own = $byProject[$project->id] ?? [];
            usort($own, fn (HourBank $a, HourBank $b): int => [$a->department_id === $user->department_id && $user->department_id !== null ? 0 : 1, $a->start_date->toDateString(), $a->id]
                <=> [$b->department_id === $user->department_id && $user->department_id !== null ? 0 : 1, $b->start_date->toDateString(), $b->id]);

            $result[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client instanceof Client ? ['id' => $project->client->id, 'name' => $project->client->name, 'icon' => $project->client->icon] : null,
                'uses_banks' => $project->usesHourBanks(),
                'banks' => array_map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name, 'department_id' => $bank->department_id], $own),
            ];
        }

        usort($result, fn (array $a, array $b): int => [$a['client'] === null ? 1 : 0, mb_strtolower($a['client']['name'] ?? ''), $a['code']]
            <=> [$b['client'] === null ? 1 : 0, mb_strtolower($b['client']['name'] ?? ''), $b['code']]);

        return $result;
    }

    /**
     * Los estados para marcar hecha o pendiente con un clic (F-059): el primero de la categoría «hecha»
     * y el estado por defecto.
     *
     * @return array{open: int|null, done: int|null}
     */
    public function toggleStatuses(): array
    {
        $statuses = TaskStatus::query()->ordered()->get();
        $open = $statuses->firstWhere('is_default', true) ?? $statuses->first(fn (TaskStatus $status): bool => ! $status->isDone());
        $done = $statuses->first(fn (TaskStatus $status): bool => $status->isDone());

        return ['open' => $open?->id, 'done' => $done?->id];
    }

    /**
     * Proyectos cuyas tareas puedo editar (miembro o gestor), o null si todos (admin y responsables),
     * como TaskPolicy::update. Dos consultas como mucho.
     *
     * @return array<int, true>|null
     */
    public function editableProjects(User $user): ?array
    {
        if ($user->isCollaborator()) {
            return array_fill_keys(array_map(intval(...), $user->projects()->pluck('projects.id')->all()), true);
        }

        if ($user->isAdmin() || $user->isDepartmentManager()) {
            return null;
        }

        $ids = [...array_map(intval(...), $user->projects()->pluck('projects.id')->all()), ...$user->managedProjectIds()];

        return array_fill_keys($ids, true);
    }

    /**
     * @return Builder<Task>
     */
    private function query(User $user): Builder
    {
        return Task::query()
            ->select('tasks.*')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->visibleTo($user)
            ->where('tasks.assignee_user_id', $user->id)
            ->with(['project:id,code,name,client_id', 'project.client:id,name,icon', 'status:id,name,category', 'creator:id,name'])
            ->withExists('timeEntries')
            ->orderByDesc('tasks.created_at')
            ->orderByDesc('tasks.id');
    }

    /**
     * @param  array<int, true>|null  $editable
     * @return array<string, mixed>
     */
    private function present(Task $task, User $user, bool $archived, ?array $editable): array
    {
        $project = $task->project;
        $client = $project->client;
        $canUpdate = $editable === null || isset($editable[$task->project_id]);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'created_at' => $task->created_at?->toIso8601String(),
            'completed' => $task->completed_at !== null,
            'status' => ['id' => $task->status->id, 'name' => $task->status->name, 'category' => $task->status->category->value],
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'client' => $client instanceof Client ? ['id' => $client->id, 'name' => $client->name, 'icon' => $client->icon] : null,
            'assigner' => $task->created_by !== null && $task->created_by !== $user->id && $task->creator !== null
                ? ['id' => $task->creator->id, 'name' => $task->creator->name]
                : null,
            'notes' => TaskNotes::toPlain($task->description),
            'notes_editable' => TaskNotes::isPlain($task->description),
            'archived' => $archived,
            'can' => [
                'update' => $canUpdate,
                'delete' => $canUpdate && ! (bool) $task->getAttribute('time_entries_exists'),
            ],
        ];
    }
}
