<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\EstimateVsActual;
use App\Domain\Forecast\ForecastPresenter;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Planificación de un proyecto real (`/proyectos/{project}/planificacion`,
 * docs/PLAN-CARGAS.md §5.2 y §5.3, D-284): sus asignaciones con plan, imputado y restante, plan
 * frente a imputado por semana y, si viene de un previsto, su origen y «estimado frente a real».
 * La ven quienes gestionan el proyecto (D-022), con el módulo `forecast`.
 */
class ProjectPlanningController extends ForecastController
{
    public function __invoke(Request $request, Project $project, ForecastPresenter $presenter, EstimateVsActual $estimate): Response
    {
        $this->authorize('viewPlanning', $project);

        /** @var User $user */
        $user = $request->user();
        $project->loadMissing(['client', 'owner', 'forecastProject.project']);
        $forecast = $project->forecastProject;

        return Inertia::render('projects/planning', [
            'project' => Plain::of(ProjectResource::make($project)),
            ...$presenter->planning($user, $project),
            'forecast' => $forecast === null ? null : [
                'id' => $forecast->id,
                'name' => $forecast->name,
                'status' => $forecast->status->value,
                'can_view' => $user->can('view', $forecast),
            ],
            'estimate' => Inertia::defer(fn (): ?array => $forecast === null ? null : $estimate->for($forecast), 'analysis'),
            'options' => Inertia::defer(fn (): array => $presenter->options(), 'options'),
            'can' => ['manage' => $user->can('manageAllocations', $project)],
        ]);
    }
}
