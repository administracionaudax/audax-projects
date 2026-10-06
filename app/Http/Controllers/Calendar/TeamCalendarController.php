<?php

namespace App\Http\Controllers\Calendar;

use App\Domain\Calendar\CalendarFilters;
use App\Domain\Calendar\CalendarNewTaskProjects;
use App\Domain\Calendar\CalendarPeople;
use App\Domain\Calendar\CalendarRange;
use App\Domain\Calendar\TeamCalendar;
use App\Domain\DayPlan\CalendarDayPlans;
use App\Domain\Tasks\TaskOptions;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\Tasks\TaskPanel;
use App\Http\Resources\Tasks\TaskPanelContext;
use App\Http\Resources\Tasks\TaskTypeOptionResource;
use App\Http\Resources\TaskStatusResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendario del equipo (/calendario, D-144): las tareas con fechas de todos los proyectos que
 * quien mira puede ver, en mes, semana o día, y la vista «Personas» (semana y día) con lo que
 * cada persona tiene cada día, su carga y sus ausencias. Filtros y fecha en la URL
 * (CalendarFilters).
 * - `calendar` (TeamCalendar y, en «Personas», CalendarPeople::rows) se recarga sola al cambiar
 *   de fecha o de filtros, y tras mover o editar una tarea (TASK_RELOAD la incluye),
 * - en la vista Día por personas, el plan del día de cada una (`calendar.day_plans`, D-254),
 * - ?tarea={id} abre el panel de la tarea sin salir del calendario: `panel` y `panelLookups` (lo
 *   que el panel necesita de su proyecto) con una recarga parcial,
 * - `creatable` (proyectos donde puede crear, con sus bolsas) y `moveTargets` solo si se piden.
 * Lo ve cualquier interno; un colaborador externo, solo sus proyectos y las personas de ellos
 * (D-134). Mover una tarea va por schedule.reschedule.* (D-057), con TaskPolicy::update.
 */
class TeamCalendarController extends Controller
{
    public function __construct(
        private readonly TeamCalendar $calendar,
        private readonly CalendarPeople $people,
        private readonly TaskOptions $options,
        private readonly TaskPanel $panel,
        private readonly TaskPanelContext $context,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $filters = CalendarFilters::fromRequest($request)->forViewer($user->isCollaborator());
        $range = CalendarRange::for($filters->view, $filters->date);
        $taskId = QueryParams::id($request->query('tarea'));

        // La tarea del panel, una vez aunque se pidan sus dos props.
        $panelTask = null;
        $loadPanelTask = function () use (&$panelTask, $taskId, $user): ?Task {
            if ($taskId === null) {
                return null;
            }

            return $panelTask ??= $this->panelTask($taskId, $user);
        };

        return Inertia::render('calendar/index', [
            'filters' => $filters->toArray(),
            'calendar' => function () use ($user, $filters, $range): array {
                $rows = $filters->people ? $this->people->rows($user, $filters, $range) : [];

                return [
                    ...$this->calendar->build($user, $filters, $range),
                    'rows' => $rows,
                    // Plan del día (D-254): en la vista Día por personas, sus líneas de ese día.
                    'day_plans' => $filters->people && $filters->view === CalendarFilters::DAY
                        ? app(CalendarDayPlans::class)->for($user, array_values(array_filter(array_map(fn (array $row): ?int => $row['person']['id'] ?? null, $rows))), $range->from)
                        : null,
                ];
            },
            'statuses' => fn (): array => Plain::of(TaskStatusResource::collection($this->options->statuses())),
            'options' => fn (): array => [
                ...$this->people->options($user),
                ...$this->projectOptions($user),
                'types' => Plain::of(TaskTypeOptionResource::collection($this->options->types())),
            ],
            'currentUser' => ['id' => $user->id, 'department_id' => $user->department_id],
            'panel' => function () use ($loadPanelTask, $user): ?array {
                $task = $loadPanelTask();

                return $task === null ? null : $this->panel->build($task, $task->project, $user);
            },
            'panelLookups' => function () use ($loadPanelTask, $user): ?array {
                $task = $loadPanelTask();

                return $task === null ? null : $this->context->lookups($task->project, $user);
            },
            'creatable' => Inertia::optional(fn (): array => app(CalendarNewTaskProjects::class)->for($user)),
            'moveTargets' => Inertia::optional(function () use ($loadPanelTask, $user): array {
                $task = $loadPanelTask();

                return $task === null ? [] : $this->context->moveTargets($task->project, $user);
            }),
        ]);
    }

    /**
     * La tarea de ?tarea= si existe y quien mira puede verla (TaskPolicy::view); si no, el panel
     * no se abre (como en la pestaña Tareas).
     */
    private function panelTask(int $taskId, User $user): ?Task
    {
        // Solo si su proyecto existe (no está borrado).
        $task = Task::query()->whereHas('project')->with('project')->find($taskId);

        return $task !== null && Gate::forUser($user)->allows('view', $task) ? $task : null;
    }

    /**
     * Proyectos sin archivar que puede ver y sus clientes (un colaborador, solo los de los suyos).
     *
     * @return array{projects: list<array{id: int, code: string, name: string, color: string, client_id: int|null}>, clients: list<array{id: int, name: string}>}
     */
    private function projectOptions(User $user): array
    {
        $projects = Project::query()
            ->notArchived()
            ->visibleTo($user)
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
}
