<?php

namespace App\Http\Controllers\Gantt;

use App\Domain\Gantt\GanttFilters;
use App\Domain\Gantt\GanttPortfolio;
use App\Domain\Gantt\GanttPreferences;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gantt multiproyecto (/gantt, SPEC §6.1, D-060): proyectos agrupados y plegables con sus tareas.
 * Filtros en la URL (cliente, departamento implicado, responsable y estado, por defecto activos) y
 * un máximo de 60 proyectos o 1.500 tareas por vista (GanttPortfolio). Lo ve cualquier interno;
 * mueve y enlaza quien puede editar las tareas de cada proyecto (TaskPolicy::update).
 */
class GanttController extends Controller
{
    public function __construct(
        private readonly GanttFilters $filters,
        private readonly GanttPortfolio $portfolio,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters->fromRequest($request);

        // Una sola construcción aunque se pidan varias props (recarga parcial de Inertia).
        $data = null;
        $load = function () use (&$data, $user, $filters): array {
            return $data ??= $this->portfolio->build($user, $filters);
        };

        return Inertia::render('gantt/index', [
            'filters' => $filters,
            'options' => fn (): array => $this->filters->options(),
            'preferences' => GanttPreferences::fromRequest($request),
            'today' => LocalTime::todayString(),
            'statuses' => fn (): array => $this->portfolio->statuses(),
            'limit' => fn (): array => $load()['limit'],
            'projects' => fn (): array => $load()['projects'],
            'tasks' => fn (): array => $load()['tasks'],
            'dependencies' => fn (): array => $load()['dependencies'],
            'range' => fn (): array => $load()['range'],
        ]);
    }
}
