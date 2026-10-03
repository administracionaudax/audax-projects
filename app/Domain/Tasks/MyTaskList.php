<?php

namespace App\Domain\Tasks;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Tasks\ProjectTasksController;
use App\Http\Resources\Tasks\MyTaskItemResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;

/**
 * Lista de Mis tareas (SPEC §6, D-037 y D-143):
 * - qué entra: las tareas (y subtareas) asignadas a mí y las que, sin estar asignadas a mí, tienen
 *   horas mías de los últimos LOGGED_DAYS días; abiertas salvo «incluir hechas»; de proyectos sin
 *   archivar que puedo ver (un colaborador externo, solo los suyos, D-134),
 * - orden por defecto «Imputadas recientemente»: primero aquellas en las que he imputado más
 *   recientemente (la última fecha de mis horas y, a igualdad, la última que registré); después el
 *   resto, por vencimiento. También por vencimiento (con las secciones de siempre), prioridad,
 *   proyecto, creación y actualización,
 * - paginación de servidor por cursor, de PER_PAGE en PER_PAGE.
 *
 * Cómo: una subconsulta con mis horas por tarea (MAX(date), MAX(created_at), índice
 * time_entries(user_id, task_id, date)) unida a las tareas, y las claves de orden como columnas de
 * una tabla derivada (nunca nulas: COALESCE), para que la paginación por cursor de Laravel compare
 * columnas reales en PostgreSQL y en SQLite. Las fechas se comparan con «>= día» y «< día
 * siguiente», que valen igual con un `date` de PostgreSQL y con el texto «AAAA-MM-DD 00:00:00» de
 * SQLite.
 */
final class MyTaskList
{
    use MatchesText;

    public const int PER_PAGE = 50;

    /** Tareas no asignadas a mí que siguen saliendo si he imputado en ellas en estos días. */
    public const int LOGGED_DAYS = 30;

    private const string NEVER = '1900-01-01';

    private const string NEVER_AT = '1900-01-01 00:00:00';

    private const string NO_DATE = '9999-12-31';

    /**
     * Claves de orden (columnas de la tabla derivada) y su dirección, por orden.
     *
     * @var array<string, list<array{0: string, 1: 'asc'|'desc'}>>
     */
    private const array ORDERS = [
        MyTaskFilters::SORT_LOGGED => [['sort_logged_on', 'desc'], ['sort_logged_at', 'desc'], ['sort_due', 'asc'], ['id', 'asc']],
        MyTaskFilters::SORT_DUE => [['sort_section', 'asc'], ['sort_due', 'asc'], ['sort_start', 'asc'], ['sort_priority', 'asc'], ['id', 'asc']],
        MyTaskFilters::SORT_PRIORITY => [['sort_priority', 'asc'], ['sort_due', 'asc'], ['id', 'asc']],
        MyTaskFilters::SORT_PROJECT => [['sort_project', 'asc'], ['sort_due', 'asc'], ['id', 'asc']],
        MyTaskFilters::SORT_CREATED => [['sort_created', 'desc'], ['id', 'desc']],
        MyTaskFilters::SORT_UPDATED => [['sort_updated', 'desc'], ['id', 'desc']],
    ];

    public function __construct(private readonly MyTaskSections $sections) {}

    /**
     * Una página de la lista.
     *
     * @param  Collection<int, TaskStatus>  $statuses
     * @return array{tasks: list<array<string, mixed>>, cursor: string|null, next_cursor: string|null}
     */
    public function page(User $user, MyTaskFilters $filters, Collection $statuses, ?string $cursor = null): array
    {
        $today = LocalTime::today();
        $orders = self::ORDERS[$filters->sort];
        $current = $this->validCursor($cursor, $orders);

        $query = Task::query()
            ->fromSub($this->inner($user, $filters, $statuses, $today), 'tasks')
            ->select('tasks.*')
            ->with([
                'project:id,code,name,color',
                'hourBank:id,name',
                'parent:id,title',
                'assignee:id,name,avatar_path,department_id,is_active',
            ])
            ->withSum('timeEntries', 'minutes');

        foreach ($orders as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        // Con un nombre que no está en la URL: Laravel no lee ?cursor= por su cuenta (sin validar).
        $page = $query->cursorPaginate(self::PER_PAGE, ['*'], '_validated_cursor', $current);

        $tasks = [];
        foreach ($page->items() as $task) {
            /** @var Task $task */
            $lastLogged = $task->getAttribute('my_last_logged_on');

            $tasks[] = [
                ...Plain::of(MyTaskItemResource::make($task)),
                'section' => $this->sections->sectionOf($task, $today),
                'assigned_to_me' => $task->assignee_user_id === $user->id,
                'my_last_logged_on' => is_string($lastLogged) ? substr($lastLogged, 0, 10) : null,
            ];
        }

        return [
            'tasks' => $tasks,
            'cursor' => $current?->encode(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * Proyectos (con su cliente) de las tareas que entran en Mis tareas, hechas o no y sin
     * filtros: las opciones de los filtros de proyecto y cliente. Dos consultas.
     *
     * @return array{projects: list<array{id: int, code: string, name: string, color: string, client_id: int|null}>, clients: list<array{id: int, name: string}>}
     */
    public function options(User $user): array
    {
        $since = LocalTime::today()->subDays(self::LOGGED_DAYS)->toDateString();

        $taskProjects = Task::query()
            ->select('tasks.project_id')
            ->visibleTo($user)
            ->where(fn (Builder $mine) => $mine->where('tasks.assignee_user_id', $user->id)
                ->orWhereIn('tasks.id', DB::table('time_entries')->select('task_id')
                    ->where('user_id', $user->id)
                    ->whereNotNull('task_id')
                    ->where('date', '>=', $since)));

        $projects = Project::query()
            ->notArchived()
            ->whereIn('id', $taskProjects)
            ->with('client:id,name')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'color', 'client_id']);

        $clients = [];
        foreach ($projects as $project) {
            if ($project->client instanceof Client) {
                $clients[$project->client->id] = ['id' => $project->client->id, 'name' => $project->client->name];
            }
        }

        usort($clients, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'projects' => array_values($projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'client_id' => $project->client_id,
            ])->all()),
            'clients' => $clients,
        ];
    }

    /**
     * Las tareas que entran, con las claves de orden como columnas.
     *
     * @param  Collection<int, TaskStatus>  $statuses
     * @return Builder<Task>
     */
    private function inner(User $user, MyTaskFilters $filters, Collection $statuses, CarbonImmutable $today): Builder
    {
        $todayDate = $today->toDateString();
        $tomorrow = $today->addDay()->toDateString();
        $nextMonday = $today->endOfWeek(CarbonImmutable::SUNDAY)->addDay()->toDateString();
        $since = $today->subDays(self::LOGGED_DAYS)->toDateString();

        $doneIds = $statuses->filter(fn (TaskStatus $status): bool => $status->isDone())->modelKeys();
        $includeDone = $filters->done || array_intersect($filters->statuses, $doneIds) !== [];

        // Mis horas por tarea: la última fecha y la última vez que registré horas en ella.
        $myTime = DB::table('time_entries')
            ->select('task_id')
            ->selectRaw('MAX(date) as last_logged_on')
            ->selectRaw('MAX(created_at) as last_logged_at')
            ->where('user_id', $user->id)
            ->whereNotNull('task_id')
            ->groupBy('task_id');

        $query = Task::query()
            ->select(array_map(fn (string $column): string => "tasks.{$column}", ProjectTasksController::LIST_COLUMNS))
            ->leftJoinSub($myTime, 'my_time', 'my_time.task_id', '=', 'tasks.id')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->leftJoin('clients', 'clients.id', '=', 'projects.client_id')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->visibleTo($user)
            ->where(fn (Builder $mine) => $mine->where('tasks.assignee_user_id', $user->id)
                ->orWhere('my_time.last_logged_on', '>=', $since))
            ->when(! $includeDone, fn (Builder $open) => $open->whereNull('tasks.completed_at'))
            ->when($filters->projects !== [], fn (Builder $q) => $q->whereIn('tasks.project_id', $filters->projects))
            ->when($filters->clients !== [], fn (Builder $q) => $q->whereIn('projects.client_id', $filters->clients))
            ->when($filters->statuses !== [], fn (Builder $q) => $q->whereIn('tasks.status_id', $filters->statuses))
            ->when($filters->priority !== null, fn (Builder $q) => $q->where('tasks.priority', $filters->priority))
            ->when($filters->types !== [], fn (Builder $q) => $q->whereIn('tasks.task_type_id', $filters->types));

        if ($filters->q !== null) {
            $this->whereMatches($query, ['tasks.title', 'projects.code', 'projects.name', 'clients.name'], $filters->q);
        }

        match ($filters->due) {
            'overdue' => $query->whereNotNull('tasks.due_date')->where('tasks.due_date', '<', $todayDate),
            'today' => $query->where(fn (Builder $day) => $day
                ->where(fn (Builder $due) => $due->where('tasks.due_date', '>=', $todayDate)->where('tasks.due_date', '<', $tomorrow))
                ->orWhere(fn (Builder $start) => $start->where('tasks.start_date', '>=', $todayDate)->where('tasks.start_date', '<', $tomorrow))),
            'week' => $query->where('tasks.due_date', '>=', $todayDate)->where('tasks.due_date', '<', $nextMonday),
            'none' => $query->whereNull('tasks.due_date')->whereNull('tasks.start_date'),
            'range' => $query->whereNotNull('tasks.due_date')
                ->when($filters->from !== null, fn (Builder $q) => $q->where('tasks.due_date', '>=', $filters->from))
                ->when($filters->to !== null, fn (Builder $q) => $q->where('tasks.due_date', '<', CarbonImmutable::parse((string) $filters->to)->addDay()->toDateString())),
            default => null,
        };

        // Claves de orden, nunca nulas (la paginación por cursor compara con su valor).
        return $query
            ->addSelect('my_time.last_logged_on as my_last_logged_on')
            ->selectRaw('COALESCE(my_time.last_logged_on, ?) as sort_logged_on', [self::NEVER])
            ->selectRaw('COALESCE(my_time.last_logged_at, ?) as sort_logged_at', [self::NEVER_AT])
            ->selectRaw('COALESCE(tasks.due_date, ?) as sort_due', [self::NO_DATE])
            ->selectRaw('COALESCE(tasks.start_date, ?) as sort_start', [self::NO_DATE])
            ->selectRaw("CASE tasks.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END as sort_priority")
            ->selectRaw('projects.code as sort_project')
            ->selectRaw('COALESCE(tasks.created_at, ?) as sort_created', [self::NEVER_AT])
            ->selectRaw('COALESCE(tasks.updated_at, ?) as sort_updated', [self::NEVER_AT])
            // Las mismas secciones que MyTaskSections, para ordenar por vencimiento.
            ->selectRaw(
                'CASE WHEN tasks.due_date IS NOT NULL AND tasks.due_date < ? THEN 0'
                .' WHEN (tasks.due_date >= ? AND tasks.due_date < ?) OR (tasks.start_date >= ? AND tasks.start_date < ?) THEN 1'
                .' WHEN tasks.due_date IS NOT NULL AND tasks.due_date < ? THEN 2'
                .' WHEN tasks.due_date IS NOT NULL OR tasks.start_date IS NOT NULL THEN 3 ELSE 4 END as sort_section',
                [$todayDate, $todayDate, $tomorrow, $todayDate, $tomorrow, $nextMonday],
            );
    }

    /**
     * El cursor de la URL solo vale si trae exactamente las claves del orden elegido y valores
     * con la forma esperada: si no (otro orden, editado a mano…), se empieza por el principio.
     *
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $orders
     */
    private function validCursor(?string $encoded, array $orders): ?Cursor
    {
        if ($encoded === null || $encoded === '' || strlen($encoded) > 1000) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);

        if (! $cursor instanceof Cursor) {
            return null;
        }

        $parameters = $cursor->toArray();
        unset($parameters['_pointsToNextItems']);
        $columns = array_column($orders, 0);

        if (! $cursor->pointsToNextItems() || array_keys($parameters) !== $columns) {
            return null;
        }

        foreach ($parameters as $column => $value) {
            $valid = match ($column) {
                'id', 'sort_section', 'sort_priority' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'sort_project' => is_string($value) && mb_strlen($value) <= 100,
                default => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d{1,6})?)?([+-]\d{2}(:?\d{2})?)?$/', $value) === 1,
            };

            if (! $valid) {
                return null;
            }
        }

        return $cursor;
    }
}
