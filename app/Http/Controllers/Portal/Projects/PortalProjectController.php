<?php

namespace App\Http\Controllers\Portal\Projects;

use App\Domain\Gantt\GanttPreferences;
use App\Domain\Portal\PortalScope;
use App\Domain\Portal\Projects\PortalProjects;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proyectos en el portal de cliente (SPEC §11, D-064). Todo sale de PortalScope:
 * - /portal/proyectos: los proyectos de su cliente abiertos al portal,
 * - /portal/proyectos/{proyecto}: tareas y estados, solo si el equipo ha abierto la vista
 *   (canViewProject); si no, o si es de otro cliente, 404,
 * - /portal/proyectos/{proyecto}/gantt: el Gantt de solo lectura, solo si está abierto aparte
 *   (canViewGantt); si no, 404.
 * Un cliente desactivado recibe 403 (PortalScope::for). Nunca costes, tarifas, importes,
 * comentarios, adjuntos ni personas por tarea.
 */
class PortalProjectController extends Controller
{
    public function __construct(private readonly PortalProjects $projects) {}

    public function index(Request $request): Response
    {
        $scope = PortalScope::for($this->user($request));

        return Inertia::render('portal/projects/index', [
            'projects' => $this->projects->open($scope),
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $scope = PortalScope::for($this->user($request));

        abort_unless($scope->canViewProject($project), 404);

        return Inertia::render('portal/projects/show', $this->projects->show($scope, $project));
    }

    public function gantt(Request $request, Project $project): Response
    {
        $scope = PortalScope::for($this->user($request));

        abort_unless($scope->canViewGantt($project), 404);

        $today = LocalTime::today();

        return Inertia::render('portal/projects/gantt', [
            ...$this->projects->gantt($scope, $project, $today),
            // Solo la escala: los colores van siempre por estado (sin responsables en el portal).
            'preferences' => ['scale' => GanttPreferences::fromRequest($request)['scale'], 'color' => GanttPreferences::DEFAULT_COLOR],
            'today' => $today->toDateString(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
