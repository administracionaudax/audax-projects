<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ComparisonPeriod;
use App\Domain\Reports\Delivery\Documents\ProjectDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Informe de un proyecto (SPEC §10.3; R2): estimado frente a real (por tarea y por tipo, con la
 * regla de subtareas del SPEC §6), horas por persona, por tipo de tarea y por semana, estado de las
 * tareas (por estado y vencidas) e hitos, con KPIs e ingreso y rentabilidad si hay permiso.
 *
 * Quién (D-044, ProjectPolicy::viewReport = viewAllTime): admins, responsables y gestores del
 * proyecto. Las horas pasan por ReportScope: un responsable que no gestiona el proyecto ve las de su
 * equipo (D-021). Exporta con ?formato=xlsx|csv&tabla=… (ProjectDocument::TABLES) y, entero, con
 * ?formato=xlsx (un libro), pdf o imprimir (Fase 9: ProjectDocument, D-139 y D-140), en la versión
 * interna y completa o en la del cliente (?version=interno|cliente, D-240 a D-242).
 */
class ProjectReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope, ExportsReports;

    public const array TABLES = ProjectDocument::TABLES;

    public function __invoke(
        Request $request,
        Project $project,
        Metrics $metrics,
        ReportCache $cache,
        ProjectDocument $document,
    ): Response|SymfonyResponse {
        $this->authorize('viewReport', $project);

        $export = $this->exportResponse($request, ReportKind::Project);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $scope = $document->scope($user, $project, $request->query());
        $data = $document->data($scope, $project);

        // Periodo en curso: comparación «al mismo punto», como el resto de dashboards (D-079).
        $comparison = ComparisonPeriod::summary($scope, $metrics, $cache, 'r2.project.'.$project->id.'.summary', withCapacity: false,
            everyAssignee: ProjectDocument::everyAssignee($user, $project));

        $project->loadMissing(['client' => fn ($query) => $query->withTrashed()->select(['id', 'name'])]);
        $filters = ComparisonPeriod::withRange($this->filterProps(new ReportScope($user, ReportFilters::fromQuery($request->query())->with(['projectIds' => [], 'clientIds' => []]))), $comparison['range']);

        return Inertia::render('reports/project', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'billing_type' => $project->billing_type->value,
                'status' => $project->status->value,
                'budget_minutes' => $project->budget_minutes,
                'client' => $project->client === null ? null : ['id' => $project->client->id, 'name' => $project->client->name],
            ],
            'filters' => $filters,
            'report_request' => $this->reportRequestProp(ReportKind::Project, ['project' => $project->id], $filters['query']),
            'scope' => ['team_only' => ! $user->isAdmin() && ! $user->isManagerOf($project)],
            'summary' => $data['summary'],
            'comparison' => $comparison['summary'],
            'byPerson' => $data['by_person'],
            'byType' => $data['by_type'],
            'weekly' => $data['weekly'],
            'estimates' => $data['estimates'],
            'tasks' => $data['tasks'],
            'milestones' => $data['milestones'],
        ]);
    }
}
