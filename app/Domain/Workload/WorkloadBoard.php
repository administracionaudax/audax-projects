<?php

namespace App\Domain\Workload;

use App\Domain\Time\Capacity;
use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La vista «Carga» (SPEC §9, D-051, D-052) de una persona con unos filtros: la matriz personas ×
 * días (o semanas), el panel de una celda y las bandejas «Sin planificar» y «Sin asignar».
 *
 * Rendimiento: el reparto se calcula UNA vez por petición con WorkloadPlanner (todas las personas
 * del alcance) y el detalle de la capacidad con Capacity::detailsForRanges (una consulta de
 * horarios, festivos y ausencias en total); las tareas que se pintan se cargan de una vez. Nada se
 * consulta por celda, por persona ni por tarea.
 *
 * @phpstan-import-type DayDetail from CapacityExplainer
 * @phpstan-import-type Reason from CapacityExplainer
 * @phpstan-import-type Reduced from CapacityExplainer
 *
 * @phpstan-type Totals array{planned: int, capacity: int}
 * @phpstan-type Cell array{planned: int, capacity: int, reason: Reason|null, reduced: Reduced|null, overdue: bool}
 * @phpstan-type Column array{key: string, from: string, to: string, today: bool, weekend: bool}
 * @phpstan-type Members array{byProject: array<int, list<int>>, users: Collection<int, User>}
 */
final class WorkloadBoard
{
    /** Tareas que se pintan como mucho en cada bandeja (el total se indica aparte). */
    public const int TRAY_LIMIT = 100;

    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    private ?WorkloadPlan $plan = null;

    /** @var array<int, array<string, DayDetail>>|null */
    private ?array $details = null;

    /** @var Collection<int, User>|null */
    private ?Collection $rows = null;

    /** @var list<Column>|null */
    private ?array $columns = null;

    /** @var Collection<int, Department>|null */
    private ?Collection $departments = null;

    /** @var list<int>|null */
    private ?array $memberProjectIds = null;

    /** @var array<int, true>|null */
    private ?array $internalBuckets = null;

    public function __construct(
        private readonly WorkloadPlanner $planner,
        private readonly Capacity $capacity,
        public readonly WorkloadScope $scope,
        public readonly WorkloadFilters $filters,
        public readonly CarbonImmutable $today,
    ) {
        [$this->from, $this->to] = $filters->horizon->bounds($today);
    }

    /**
     * Quien solo ve su fila no tiene filtros de persona ni de departamento (D-052).
     */
    public static function for(User $viewer, WorkloadFilters $filters, ?CarbonImmutable $today = null): self
    {
        $scope = new WorkloadScope($viewer);

        return new self(
            app(WorkloadPlanner::class),
            app(Capacity::class),
            $scope,
            $scope->seesTeam() ? $filters : $filters->withoutPeopleFilters(),
            CarbonImmutable::parse(($today ?? LocalTime::today())->toDateString()),
        );
    }

    /**
     * @return array{key: string, from: string, to: string, by_week: bool, today: string, options: list<string>}
     */
    public function horizon(): array
    {
        return [
            'key' => $this->filters->horizon->value,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'by_week' => $this->filters->horizon->byWeek(),
            'today' => $this->today->toDateString(),
            'options' => WorkloadHorizon::values(),
        ];
    }

    /**
     * @return array{query: array<string, string|list<int>>, sees_team: bool, sees_unassigned: bool}
     */
    public function filterProps(): array
    {
        return [
            'query' => $this->filters->toQuery(),
            'sees_team' => $this->scope->seesTeam(),
            'sees_unassigned' => $this->scope->seesUnassigned(),
        ];
    }

    public function plan(): WorkloadPlan
    {
        return $this->plan ??= $this->planner->plan(
            $this->scope->peopleIds(),
            $this->from,
            $this->to,
            $this->today,
            $this->filters->plannerFilters(),
        );
    }

    /**
     * Filas: las personas del alcance con los filtros de persona y departamento.
     *
     * @return Collection<int, User>
     */
    public function rows(): Collection
    {
        $users = $this->filters->userIds;
        $departments = $this->filters->departmentIds;

        return $this->rows ??= $this->scope->people()
            ->filter(fn (User $person): bool => ($users === [] || in_array($person->id, $users, true))
                && ($departments === [] || in_array($person->department_id, $departments, true)))
            ->values();
    }

    /**
     * Columnas: días (sin sábados ni domingos si nadie de las filas trabaja ni tiene carga, salvo
     * que no quede ningún otro) o semanas de lunes a domingo (la primera, desde hoy).
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $today = $this->today->toDateString();
        $columns = [];

        if ($this->filters->horizon->byWeek()) {
            $start = $this->from;

            while ($start <= $this->to) {
                $sunday = $start->addDays(7 - $start->dayOfWeekIso);
                $end = $sunday < $this->to ? $sunday : $this->to;
                $columns[] = [
                    'key' => $start->toDateString(),
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                    'today' => $start->toDateString() <= $today && $today <= $end->toDateString(),
                    'weekend' => false,
                ];
                $start = $end->addDay();
            }

            return $this->columns = $columns;
        }

        $rowIds = array_values(array_map(fn (User $person): int => $person->id, $this->rows()->all()));

        foreach (CarbonPeriod::create($this->from, $this->to) as $day) {
            $date = $day->toDateString();
            $weekend = $day->dayOfWeekIso >= 6;

            if ($weekend && ! $this->hasActivity($rowIds, $date)) {
                continue;
            }

            $columns[] = ['key' => $date, 'from' => $date, 'to' => $date, 'today' => $date === $today, 'weekend' => $weekend];
        }

        // Un sábado o un domingo, la semana actual puede quedarse sin días laborables: se enseñan
        // los que quedan (grises, «no laborable»), nunca una matriz sin columnas.
        if ($columns === []) {
            foreach (CarbonPeriod::create($this->from, $this->to) as $day) {
                $date = $day->toDateString();
                $columns[] = ['key' => $date, 'from' => $date, 'to' => $date, 'today' => $date === $today, 'weekend' => $day->dayOfWeekIso >= 6];
            }
        }

        return $this->columns = $columns;
    }

    /**
     * Matriz agrupada por departamento, con totales por persona, por departamento y por columna.
     *
     * @return array{columns: list<Column>, groups: list<array{department: array{id: int|null, name: string|null, color: string|null}, people: list<array{id: int, name: string, is_me: bool, cells: list<Cell>, total: Totals}>, totals: list<Totals>, total: Totals}>, totals: list<Totals>, total: Totals}
     */
    public function matrix(): array
    {
        $columns = $this->columns();
        $plan = $this->plan();
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $departments = $this->departments();

        $byDepartment = $this->rows()->groupBy(fn (User $person): int => $person->department_id ?? 0);
        $groups = [];

        foreach ($byDepartment as $departmentKey => $people) {
            $department = $departmentKey === 0 ? null : $departments->get((int) $departmentKey);
            $rows = [];

            foreach ($people as $person) {
                $cells = [];

                foreach ($columns as $column) {
                    $cells[] = $this->cellFor($person->id, $column['from'], $column['to']);
                }

                $rows[] = [
                    'id' => $person->id,
                    'name' => $person->name,
                    'is_me' => $person->id === $this->scope->viewer->id,
                    'cells' => $cells,
                    'total' => [
                        'planned' => $plan->loadBetween($person->id, $from, $to),
                        'capacity' => $plan->capacityBetween($person->id, $from, $to),
                    ],
                ];
            }

            $groups[] = [
                'department' => [
                    'id' => $department?->id,
                    'name' => $department?->name,
                    'color' => $department?->color,
                ],
                'people' => $rows,
                'totals' => self::columnTotals(array_map(fn (array $row): array => $row['cells'], $rows), count($columns)),
                'total' => self::sum(array_column($rows, 'total')),
            ];
        }

        // Por nombre de departamento; «Sin departamento», al final.
        usort($groups, fn (array $a, array $b): int => [$a['department']['id'] === null, mb_strtolower((string) $a['department']['name'])]
            <=> [$b['department']['id'] === null, mb_strtolower((string) $b['department']['name'])]);

        return [
            'columns' => $columns,
            'groups' => $groups,
            'totals' => self::columnTotals(array_column($groups, 'totals'), count($columns)),
            'total' => self::sum(array_column($groups, 'total')),
        ];
    }

    /**
     * Personas del alcance con su carga en el horizonte: opciones del filtro de persona y de los
     * selectores de responsable (se elige a quien tiene hueco).
     *
     * @return list<array{id: int, name: string, department_id: int|null, department: string|null, planned: int, capacity: int, is_me: bool}>
     */
    public function people(): array
    {
        $plan = $this->plan();
        $departments = $this->departments();
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();

        return array_values($this->scope->people()->map(fn (User $person): array => [
            'id' => $person->id,
            'name' => $person->name,
            'department_id' => $person->department_id,
            'department' => $person->department_id === null ? null : $departments->get($person->department_id)?->name,
            'planned' => $plan->loadBetween($person->id, $from, $to),
            'capacity' => $plan->capacityBetween($person->id, $from, $to),
            'is_me' => $person->id === $this->scope->viewer->id,
        ])->all());
    }

    /**
     * Opciones de los filtros: departamentos (solo quien ve a su equipo), clientes y proyectos no
     * archivados (todos los internos ven todos los proyectos, D-021).
     *
     * @return array{departments: list<array{id: int, name: string, color: string}>, clients: list<array{id: int, name: string}>, projects: list<array{id: int, name: string, client_id: int|null}>}
     */
    public function options(): array
    {
        $projects = Project::query()->notArchived()->orderBy('code')->get(['id', 'code', 'name', 'client_id']);
        $managed = $this->scope->managedDepartmentIds();

        $departments = $this->scope->seesTeam()
            ? $this->departments()
                ->filter(fn (Department $department): bool => ! $department->trashed()
                    && ($this->scope->isAdmin() || in_array($department->id, $managed, true)))
                ->sortBy(fn (Department $department): string => mb_strtolower($department->name))
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])
                ->values()
                ->all()
            : [];
        $departments = array_values($departments);

        return [
            'departments' => $departments,
            'clients' => array_values(Client::query()
                ->whereIn('id', $projects->pluck('client_id')->filter()->unique()->values())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])
                ->all()),
            'projects' => array_values($projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->code.' · '.$project->name,
                'client_id' => $project->client_id,
            ])->all()),
        ];
    }

    /**
     * Panel de una celda (?celda=persona:fecha): las tareas que forman esa carga, con los minutos que
     * ponen en ella, y el detalle de cada día. Una persona fuera del alcance → 403 (D-052).
     *
     * @return array<string, mixed>|null
     *
     * @throws AuthorizationException
     */
    public function cell(): ?array
    {
        $userId = $this->filters->cellUserId;
        $date = $this->filters->cellDate;

        if ($userId === null || $date === null) {
            return null;
        }

        if (! $this->scope->includes($userId)) {
            throw new AuthorizationException(__('workload.errors.person_out_of_scope'));
        }

        if ($date < $this->from->toDateString() || $date > $this->to->toDateString()) {
            return null;
        }

        [$from, $to] = $this->cellRange($date);
        $plan = $this->plan();
        /** @var User $person */
        $person = $this->scope->people()->firstWhere('id', $userId);

        $minutes = [];
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key = $day->toDateString();

            foreach ($plan->contributions[$userId][$key] ?? [] as $taskId => $taskMinutes) {
                $minutes[$taskId] = ($minutes[$taskId] ?? 0) + $taskMinutes;
            }

            $detail = $this->details()[$userId][$key] ?? ['base' => 0, 'minutes' => 0, 'holiday' => null, 'absence' => null];
            $days[] = [
                'date' => $key,
                'planned' => $plan->loadOn($userId, $key),
                'capacity' => $plan->capacity[$userId][$key] ?? 0,
                'base' => $detail['base'],
                ...CapacityExplainer::explain([$key => $detail]),
            ];
        }

        arsort($minutes);
        $tasks = $this->loadTasks(array_keys($minutes));
        $members = $this->projectMembers($tasks);
        $rows = [];

        foreach ($minutes as $taskId => $taskMinutes) {
            $task = $tasks->get($taskId);

            if ($task !== null) {
                $rows[] = [...$this->taskData($task, $members), 'minutes' => $taskMinutes];
            }
        }

        return [
            // La de la columna (en 3 meses, el primer día de la semana en el horizonte), aunque la
            // URL traiga otro día de esa semana: así se marca la celda abierta en la matriz.
            'key' => $userId.':'.$from->toDateString(),
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'department' => $person->department_id === null ? null : $this->departments()->get($person->department_id)?->name,
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            ...$this->cellFor($userId, $from->toDateString(), $to->toDateString()),
            'days' => $days,
            'tasks' => $rows,
            'extra_people' => $this->extraPeople($members),
        ];
    }

    /**
     * Bandejas (D-051):
     * - «Sin planificar»: tareas de las filas sin estimación o sin entrega,
     * - «Sin asignar» por departamento (el de la bolsa o, si no, el del tipo), solo para quien reparte.
     * Sin las tareas «cajón» de los proyectos internos (InternalBuckets). Cada bandeja pinta como
     * mucho TRAY_LIMIT tareas, elegidas DESPUÉS de ordenar: las vencidas primero y después por
     * entrega, así que una vencida nunca se queda fuera por el corte.
     *
     * @return array<string, mixed>
     */
    public function trays(): array
    {
        $plan = $this->plan();
        $rowIndex = array_fill_keys(array_map(fn (User $person): int => $person->id, $this->rows()->all()), true);
        $buckets = $this->internalBuckets();
        $unplanned = array_values(array_filter($plan->unplanned, fn (array $item): bool => isset($rowIndex[$item['user_id']]) && ! isset($buckets[$item['task_id']])));

        /** @var array<int, list<array{task_id: int, remaining_minutes: int}>> $unassigned 0 = sin departamento */
        $unassigned = [];
        if ($this->scope->seesUnassigned()) {
            foreach ($plan->unassigned as $key => $items) {
                $departmentId = $key === '' ? null : (int) $key;

                $items = array_values(array_filter($items, fn (array $item): bool => ! isset($buckets[$item['task_id']])));

                if ($items !== [] && $this->scope->seesUnassignedOf($departmentId) && $this->matchesDepartmentFilter($departmentId)) {
                    $unassigned[$departmentId ?? 0] = $items;
                }
            }
        }

        $unassignedIds = array_merge(...array_values(array_map(fn (array $items): array => array_column($items, 'task_id'), $unassigned)));

        // El orden de las bandejas, con una consulta ligera, antes de cortar.
        $rank = array_flip($this->ranked([...array_column($unplanned, 'task_id'), ...$unassignedIds]));
        usort($unplanned, fn (array $a, array $b): int => ($rank[$a['task_id']] ?? PHP_INT_MAX) <=> ($rank[$b['task_id']] ?? PHP_INT_MAX));
        $shownUnplanned = array_slice($unplanned, 0, self::TRAY_LIMIT);
        $shownUnassigned = array_fill_keys(array_slice(self::sortIds($unassignedIds, $rank), 0, self::TRAY_LIMIT), true);

        $tasks = $this->loadTasks([
            ...array_column($shownUnplanned, 'task_id'),
            ...array_keys($shownUnassigned),
        ]);
        $members = $this->projectMembers($tasks);
        $people = $this->scope->people()->keyBy('id');

        $unplannedRows = [];
        foreach ($shownUnplanned as $item) {
            $task = $tasks->get($item['task_id']);

            if ($task === null) {
                continue;
            }

            $unplannedRows[] = [
                ...$this->taskData($task, $members),
                'assignee' => ['id' => $item['user_id'], 'name' => (string) $people->get($item['user_id'])?->name],
                'missing' => self::missing($task),
            ];
        }

        $groups = [];
        foreach ($unassigned as $departmentKey => $items) {
            $department = $departmentKey === 0 ? null : $this->departments()->get($departmentKey);
            $rows = [];

            foreach (self::sortIds(array_column($items, 'task_id'), $rank) as $taskId) {
                $task = isset($shownUnassigned[$taskId]) ? $tasks->get($taskId) : null;

                if ($task !== null) {
                    $rows[] = $this->taskData($task, $members);
                }
            }

            $groups[] = [
                'department' => ['id' => $department?->id, 'name' => $department?->name, 'color' => $department?->color],
                'total' => count($items),
                'remaining_minutes' => (int) array_sum(array_column($items, 'remaining_minutes')),
                'tasks' => $rows,
            ];
        }

        usort($groups, fn (array $a, array $b): int => [$a['department']['id'] === null, mb_strtolower((string) $a['department']['name'])]
            <=> [$b['department']['id'] === null, mb_strtolower((string) $b['department']['name'])]);

        return [
            'limit' => self::TRAY_LIMIT,
            'unplanned' => [
                'total' => count($unplanned),
                'tasks' => $unplannedRows,
            ],
            'unassigned' => [
                'visible' => $this->scope->seesUnassigned(),
                'total' => (int) array_sum(array_column($groups, 'total')),
                'groups' => $groups,
            ],
            'extra_people' => $this->extraPeople($members),
        ];
    }

    /**
     * Orden de las bandejas: por entrega (sin fecha al final; las vencidas, que son las de entrega
     * más antigua, quedan las primeras), por título y por id. Una consulta ligera (id, título y
     * entrega) de todas las candidatas, sin cargar las tareas.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ranked(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $keys = array_map(
            fn (object $row): array => [
                $row->due_date === null ? '9999-12-31' : substr((string) $row->due_date, 0, 10),
                mb_strtolower((string) $row->title),
                (int) $row->id,
            ],
            Task::query()->whereKey(array_values(array_unique($ids)))->toBase()->get(['id', 'title', 'due_date'])->all(),
        );
        sort($keys);

        return array_column($keys, 2);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, int>  $rank  id → posición
     * @return list<int>
     */
    private static function sortIds(array $ids, array $rank): array
    {
        usort($ids, fn (int $a, int $b): int => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX));

        return $ids;
    }

    /**
     * Lo que le falta a una tarea para sumar carga.
     *
     * @return list<'estimate'|'due_date'>
     */
    private static function missing(Task $task): array
    {
        return array_values(array_filter([
            $task->estimated_minutes ? null : 'estimate',
            $task->due_date === null ? 'due_date' : null,
        ]));
    }

    /**
     * @return array<int, true>
     */
    private function internalBuckets(): array
    {
        return $this->internalBuckets ??= InternalBuckets::ids();
    }

    /**
     * Carga, capacidad y su explicación de una persona entre dos fechas (una celda o un total).
     *
     * @return Cell
     */
    private function cellFor(int $userId, string $from, string $to): array
    {
        $plan = $this->plan();
        $days = array_filter($this->details()[$userId] ?? [], fn (string $date): bool => $date >= $from && $date <= $to, ARRAY_FILTER_USE_KEY);
        $today = $this->today->toDateString();
        $overdue = false;

        if ($today >= $from && $today <= $to) {
            $overdueIds = array_flip($plan->overdue);

            foreach (array_keys($plan->contributions[$userId][$today] ?? []) as $taskId) {
                $overdue = $overdue || isset($overdueIds[$taskId]);
            }
        }

        return [
            'planned' => $plan->loadBetween($userId, $from, $to),
            'capacity' => $plan->capacityBetween($userId, $from, $to),
            ...CapacityExplainer::explain($days),
            'overdue' => $overdue,
        ];
    }

    /**
     * @param  list<int>  $rowIds
     */
    private function hasActivity(array $rowIds, string $date): bool
    {
        $plan = $this->plan();

        foreach ($rowIds as $userId) {
            if (($plan->capacity[$userId][$date] ?? 0) > 0 || $plan->loadOn($userId, $date) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Días de la celda que contiene $date: ese día o su semana (dentro del horizonte).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function cellRange(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        if (! $this->filters->horizon->byWeek()) {
            return [$day, $day];
        }

        $monday = $day->subDays($day->dayOfWeekIso - 1);
        $sunday = $monday->addDays(6);

        return [$monday > $this->from ? $monday : $this->from, $sunday < $this->to ? $sunday : $this->to];
    }

    /**
     * Detalle de la capacidad (festivos, ausencias) de todas las personas del alcance en el horizonte.
     *
     * @return array<int, array<string, DayDetail>>
     */
    private function details(): array
    {
        if ($this->details !== null) {
            return $this->details;
        }

        $ids = $this->scope->peopleIds();
        $details = $this->capacity->detailsForRanges(array_map(
            fn (int $id): array => ['user_id' => $id, 'from' => $this->from, 'to' => $this->to],
            $ids,
        ));

        return $this->details = $ids === [] ? [] : array_combine($ids, $details);
    }

    /**
     * Todos los departamentos (tabla pequeña), también los borrados, por id.
     *
     * @return Collection<int, Department>
     */
    private function departments(): Collection
    {
        return $this->departments ??= Department::query()->withTrashed()->get(['id', 'name', 'color', 'deleted_at'])->keyBy('id');
    }

    /**
     * Con filtro de departamento, sus departamentos; con filtro de persona, los de esas personas.
     */
    private function matchesDepartmentFilter(?int $departmentId): bool
    {
        if ($this->filters->departmentIds !== []) {
            return $departmentId !== null && in_array($departmentId, $this->filters->departmentIds, true);
        }

        if ($this->filters->userIds !== []) {
            return in_array($departmentId, $this->rows()->pluck('department_id')->all(), true);
        }

        return true;
    }

    /**
     * Las tareas que se pintan, con lo que se muestra de cada una (sin consultas por tarea).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Task> por id
     */
    private function loadTasks(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Task::query()
            ->whereKey(array_values(array_unique($ids)))
            ->select(['id', 'project_id', 'hour_bank_id', 'parent_task_id', 'task_type_id', 'assignee_user_id', 'title', 'start_date', 'due_date', 'estimated_minutes'])
            ->withSum('timeEntries', 'minutes')
            ->with([
                'project:id,code,name,color,client_id',
                'project.client:id,name',
                'hourBank:id,name,department_id',
                'type:id,department_id',
                'parent:id,title',
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * Miembros internos activos de los proyectos que quien mira gestiona (a quién puede asignar sus
     * tareas un gestor). Dos consultas como mucho, y ninguna si no gestiona ninguno.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Members
     */
    private function projectMembers(Collection $tasks): array
    {
        $projectIds = array_values(array_unique(array_filter(
            $tasks->pluck('project_id')->map(fn (mixed $id): int => (int) $id)->all(),
            fn (int $id): bool => $this->scope->viewer->isManagerOf($id),
        )));

        if ($projectIds === []) {
            return ['byProject' => [], 'users' => new Collection];
        }

        $pairs = DB::table('project_members')->whereIn('project_id', $projectIds)->get(['project_id', 'user_id']);
        $users = User::query()->active()->internal()
            ->whereKey($pairs->pluck('user_id')->unique()->values()->all())
            ->orderBy('name')
            ->get(['id', 'name', 'department_id'])
            ->keyBy('id');

        $byProject = [];
        foreach ($pairs as $pair) {
            $userId = (int) $pair->user_id;

            if ($users->has($userId)) {
                $byProject[(int) $pair->project_id][] = $userId;
            }
        }

        return ['byProject' => $byProject, 'users' => $users];
    }

    /**
     * Personas que se pueden elegir como responsable pero están fuera del alcance (miembros de los
     * proyectos que gestiona): solo el nombre, nunca su carga.
     *
     * @param  Members  $members
     * @return list<array{id: int, name: string, department: string|null}>
     */
    private function extraPeople(array $members): array
    {
        return array_values($members['users']
            ->reject(fn (User $user): bool => $this->scope->includes($user->id))
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'department' => $user->department_id === null ? null : $this->departments()->get($user->department_id)?->name,
            ])
            ->all());
    }

    /**
     * Lo que se muestra de una tarea y lo que se puede hacer con ella desde la vista Carga.
     *
     * @param  Members  $members
     * @return array<string, mixed>
     */
    private function taskData(Task $task, array $members): array
    {
        $logged = (int) ($task->time_entries_sum_minutes ?? 0);
        $canEdit = $this->canEdit($task) && $this->scope->reaches($task);
        $options = $canEdit ? $this->scope->assigneeOptions($task, $members['byProject'][$task->project_id] ?? []) : [];

        return [
            'id' => $task->id,
            'title' => $task->title,
            'parent_title' => $task->parent?->title,
            'project' => [
                'id' => $task->project->id,
                'code' => $task->project->code,
                'name' => $task->project->name,
                'color' => $task->project->color,
            ],
            'client' => $task->project->client?->name,
            'hour_bank' => $task->hourBank?->name,
            'assignee_id' => $task->assignee_user_id,
            'estimated_minutes' => $task->estimated_minutes,
            'logged_minutes' => $logged,
            'remaining_minutes' => max((int) $task->estimated_minutes - $logged, 0),
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'overdue' => $task->due_date !== null && $task->due_date->toDateString() < $this->today->toDateString(),
            'can_edit' => $canEdit,
            'assignee_ids' => $options === [] ? null : $options,
        ];
    }

    /**
     * El mismo criterio que TaskPolicy::update (D-031: miembros del proyecto y quien lo gestiona),
     * con la pertenencia a proyectos cargada de una vez. El guardado lo vuelve a comprobar con la
     * política (WorkloadTaskController); un test comprueba que coinciden.
     */
    private function canEdit(Task $task): bool
    {
        $viewer = $this->scope->viewer;

        if ($viewer->isAdmin() || $viewer->isDepartmentManager() || $viewer->isManagerOf($task->project_id)) {
            return true;
        }

        $this->memberProjectIds ??= array_values($viewer->projects()->pluck('projects.id')->map(fn (mixed $id): int => (int) $id)->all());

        return in_array($task->project_id, $this->memberProjectIds, true);
    }

    /**
     * Suma, columna a columna, varias filas de celdas (o de totales).
     *
     * @param  list<list<array{planned: int, capacity: int}>>  $rows
     * @return list<Totals>
     */
    private static function columnTotals(array $rows, int $columns): array
    {
        $totals = [];

        for ($index = 0; $index < $columns; $index++) {
            $totals[] = self::sum(array_map(fn (array $row): array => $row[$index], $rows));
        }

        return $totals;
    }

    /**
     * @param  list<array{planned: int, capacity: int}>  $totals
     * @return Totals
     */
    private static function sum(array $totals): array
    {
        return [
            'planned' => (int) array_sum(array_column($totals, 'planned')),
            'capacity' => (int) array_sum(array_column($totals, 'capacity')),
        ];
    }
}
