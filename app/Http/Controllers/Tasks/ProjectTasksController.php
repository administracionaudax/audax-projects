<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Planning\CalendarPeriod;
use App\Domain\Planning\TaskCalendar;
use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Tasks\TaskOptions;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\Tasks\TaskBankOptionResource;
use App\Http\Resources\Tasks\TaskListItemResource;
use App\Http\Resources\Tasks\TaskPanel;
use App\Http\Resources\Tasks\TaskPanelContext;
use App\Http\Resources\Tasks\TaskTypeOptionResource;
use App\Http\Resources\TaskStatusResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Tareas del proyecto (SPEC §6): /proyectos/{project}/tareas.
 * - vista Lista (agrupable), Kanban (?vista=kanban) o Calendario (?vista=calendario&mes=2026-10 o
 *   &semana=2026-10-05, D-061: prop `calendar`, App\Domain\Planning\TaskCalendar), con filtros en la
 *   URL (en español),
 * - las completadas, ocultas salvo ?completadas=1,
 * - tareas raíz con sus subtareas (un nivel); sin N+1 y sin la descripción (no se selecciona),
 * - ?tarea={id} abre el panel lateral: la prop `panel` se pide con una recarga parcial,
 * - `moveTargets` (proyectos donde puede crear tareas) solo se envía si se pide (mover una tarea).
 */
class ProjectTasksController extends Controller
{
    /**
     * Columnas de la lista: todas salvo la descripción (TaskResource la omite si no se selecciona).
     */
    public const array LIST_COLUMNS = [
        'id', 'project_id', 'hour_bank_id', 'parent_task_id', 'title', 'task_type_id', 'status_id',
        'priority', 'assignee_user_id', 'start_date', 'due_date', 'estimated_minutes', 'is_billable',
        'is_milestone', 'position', 'completed_at', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    public const array GROUPS = ['status', 'assignee', 'bank', 'type', 'none'];

    /**
     * Parámetros de la URL (en español) → filtros.
     */
    private const array QUERY = [
        'view' => 'vista',
        'assignee' => 'responsable',
        'bank' => 'bolsa',
        'type' => 'tipo',
        'priority' => 'prioridad',
        'status' => 'estado',
        'mine' => 'mias',
        'completed' => 'completadas',
        'group' => 'agrupar',
        'task' => 'tarea',
        'month' => 'mes',
        'week' => 'semana',
    ];

    /**
     * Valor de ?vista= → vista.
     */
    private const array VIEWS = ['kanban' => 'kanban', 'calendario' => 'calendar'];

    public function __construct(
        private readonly TaskOptions $options,
        private readonly TaskPanel $panel,
        private readonly TaskCalendar $calendar,
        private readonly TaskPanelContext $context,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $project->loadMissing(['client', 'owner']);
        $filters = $this->filters($request);
        $canManage = $user->canManageProject($project);
        $probe = (new Task(['project_id' => $project->id]))->setRelation('project', $project);
        $taskId = $this->intOrNull($request->query(self::QUERY['task']));
        $view = self::VIEWS[$this->stringOrEmpty($request->query(self::QUERY['view']))] ?? 'list';
        $calendar = $view === 'calendar';

        return Inertia::render('projects/tasks', [
            'project' => Plain::of(ProjectResource::make($project)),
            'canManage' => $canManage,
            'can' => [
                'create' => Gate::allows('create', [Task::class, $project]),
                'update' => Gate::allows('update', $probe),
            ],
            'view' => $view,
            'filters' => $filters,
            // El calendario trae sus propias tareas (prop `calendar`): la lista no se calcula.
            'tasks' => fn (): array => $calendar ? [] : Plain::of(TaskListItemResource::collection($this->tasks($project, $user, $filters))),
            'hiddenCompletedCount' => fn (): int => $filters['completed'] || $calendar
                ? 0
                : Task::query()->where('project_id', $project->id)->roots()->whereNotNull('completed_at')->count(),
            'calendar' => fn (): ?array => $calendar
                ? $this->calendar->build($project, $user, $filters, CalendarPeriod::resolve(
                    $request->query(self::QUERY['month']),
                    $request->query(self::QUERY['week']),
                    LocalTime::today(),
                ))
                : null,
            'statuses' => fn (): array => Plain::of(TaskStatusResource::collection($this->options->statuses())),
            'types' => fn (): array => Plain::of(TaskTypeOptionResource::collection($this->options->types($this->context->usedTypeIds($project)))),
            'banks' => fn (): array => Plain::of(TaskBankOptionResource::collection($this->options->banks($project, $user))),
            'users' => fn (): array => $this->context->users($project, $user),
            'currentUser' => ['id' => $user->id, 'department_id' => $user->department_id],
            'maxAttachmentMb' => AttachmentStorage::maxMegabytes(),
            'panel' => fn (): ?array => $this->panelFor($taskId, $project, $user),
            'moveTargets' => Inertia::optional(fn (): array => $this->context->moveTargets($project, $user)),
        ]);
    }

    /**
     * @param  array{assignee: int|'none'|null, bank: int|null, type: int|null, priority: string|null, status: int|null, mine: bool, completed: bool, group: string}  $filters
     * @return Collection<int, Task>
     */
    private function tasks(Project $project, User $user, array $filters): Collection
    {
        $apply = function (Builder $query) use ($filters, $user): void {
            $query->when($filters['assignee'] === 'none', fn (Builder $q) => $q->whereNull('assignee_user_id'))
                ->when(is_int($filters['assignee']), fn (Builder $q) => $q->where('assignee_user_id', $filters['assignee']))
                ->when($filters['mine'], fn (Builder $q) => $q->where('assignee_user_id', $user->id))
                ->when($filters['bank'] !== null, fn (Builder $q) => $q->where('hour_bank_id', $filters['bank']))
                ->when($filters['type'] !== null, fn (Builder $q) => $q->where('task_type_id', $filters['type']))
                ->when($filters['priority'] !== null, fn (Builder $q) => $q->where('priority', $filters['priority']))
                ->when($filters['status'] !== null, fn (Builder $q) => $q->where('status_id', $filters['status']));
        };
        $filtered = $filters['assignee'] !== null || $filters['mine'] || $filters['bank'] !== null
            || $filters['type'] !== null || $filters['priority'] !== null || $filters['status'] !== null;

        $subtaskColumns = array_map(fn (string $column): string => "tasks.{$column}", self::LIST_COLUMNS);

        return Task::query()
            ->select(self::LIST_COLUMNS)
            ->where('project_id', $project->id)
            ->roots()
            ->when(! $filters['completed'], fn (Builder $query) => $query->open())
            // Una tarea raíz aparece si cumple los filtros o si alguna de sus subtareas los cumple.
            ->when($filtered, fn (Builder $query) => $query->where(fn (Builder $match) => $match->where($apply)
                ->orWhereHas('subtasks', fn (Builder $subtasks) => $subtasks->where($apply)
                    ->when(! $filters['completed'], fn (Builder $open) => $open->open()))))
            ->with([
                'assignee:id,name,avatar_path,department_id,is_active',
                'subtasks' => fn ($query) => $query->select($subtaskColumns)
                    ->with('assignee:id,name,avatar_path,department_id,is_active')
                    ->withSum('timeEntries', 'minutes')
                    ->withCount(['comments', 'attachments'])
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->withSum('timeEntries', 'minutes')
            ->withCount(['subtasks', 'comments', 'attachments'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function panelFor(?int $taskId, Project $project, User $user): ?array
    {
        if ($taskId === null) {
            return null;
        }

        $task = Task::query()->whereKey($taskId)->where('project_id', $project->id)->first();

        if ($task === null || Gate::denies('view', $task)) {
            return null;
        }

        return $this->panel->build($task, $project, $user);
    }

    /**
     * @return array{assignee: int|'none'|null, bank: int|null, type: int|null, priority: string|null, status: int|null, mine: bool, completed: bool, group: string}
     */
    private function filters(Request $request): array
    {
        $assignee = $request->query(self::QUERY['assignee']);
        $priority = TaskPriority::tryFrom($this->stringOrEmpty($request->query(self::QUERY['priority'])));
        $group = $this->stringOrEmpty($request->query(self::QUERY['group']));

        return [
            'assignee' => $assignee === 'ninguno' ? 'none' : $this->intOrNull($assignee),
            'bank' => $this->intOrNull($request->query(self::QUERY['bank'])),
            'type' => $this->intOrNull($request->query(self::QUERY['type'])),
            'priority' => $priority?->value,
            'status' => $this->intOrNull($request->query(self::QUERY['status'])),
            'mine' => $request->boolean(self::QUERY['mine']),
            'completed' => $request->boolean(self::QUERY['completed']),
            'group' => in_array($group, self::GROUPS, true) ? $group : 'status',
        ];
    }

    /**
     * Los parámetros de la URL pueden llegar como arrays (?tipo[]=…): se ignoran.
     */
    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
