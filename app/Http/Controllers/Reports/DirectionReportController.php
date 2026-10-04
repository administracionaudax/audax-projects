<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Delivery\Documents\DirectionDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\HourBanksAtRisk;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Reports\ReportCache;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Dashboard de dirección (/informes/direccion, SPEC §10.1, D-044):
 * - admin: toda la agencia; responsable: limitado a los departamentos que dirige (se le imponen
 *   siempre: con el filtro de departamento solo puede acotar dentro de los suyos); el resto, 403,
 * - KPIs (con variación si comparar=1; en un periodo en curso, frente a los mismos días del
 *   anterior: BuildsDashboards::summaries), evolución semanal o mensual, reparto por departamento y
 *   por cliente, top 10 de clientes y proyectos, bolsas en riesgo y tareas vencidas,
 * - ingreso, coste y margen solo con view-financials (también en la exportación),
 * - ?formato=xlsx|csv&tabla=clientes|proyectos|departamentos exporta el reparto completo y
 *   tabla=bolsas-en-riesgo|tareas-vencidas, las listas enteras (SPEC §10: cualquier tabla);
 *   ?formato=pdf, el informe entero y ?formato=imprimir, el mismo HTML para imprimir (Fase 9:
 *   DirectionDocument y ReportFileGenerator, D-139 y D-140).
 */
class DirectionReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope, ExportsReports;

    public const int TOP = DirectionDocument::TOP;

    public const array TABLES = DirectionDocument::TABLES;

    /** Listas que también se exportan enteras (en la página, las primeras). */
    public const array LISTS = DirectionDocument::LISTS;

    public function __invoke(
        Request $request,
        ReportCache $cache,
        HourBanksAtRisk $atRisk,
        OverdueTasks $overdue,
        DirectionDocument $document,
    ): Response|SymfonyResponse {
        Gate::authorize('viewDirectionReport', Department::class);

        $export = $this->exportResponse($request, ReportKind::Direction);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $scope = $document->scope($user, $request->query());

        $summaries = $document->pageSummaries($scope);
        $bucket = $this->seriesBucket($scope->filters);
        // Las tareas vencidas (y sus días de retraso) dependen de hoy: el bloque lleva la fecha.
        $page = $cache->remember($scope, self::daily('r1.direction.page.'.$bucket->value), fn (): array => [
            'series' => $document->series($scope, $bucket),
            'at_risk' => $atRisk->forScope($scope),
            'overdue' => $overdue->forScope($scope),
        ]);

        $clients = $this->top($document->breakdown($scope, 'clientes'), self::TOP);
        $projects = $this->top($document->breakdown($scope, 'proyectos'), self::TOP);
        $filters = self::withComparisonRange($this->filterProps($scope), $summaries['comparison_range']);

        return Inertia::render('reports/direction', [
            'filters' => $filters,
            'report_request' => $this->reportRequestProp(ReportKind::Direction, [], $filters['query']),
            'limited_to' => $user->isAdmin() ? null : Department::query()
                ->whereKey($scope->filters->departmentIds)->orderBy('name')->pluck('name')->all(),
            'summary' => $summaries['summary'],
            'comparison' => $summaries['comparison'],
            'comparison_partial' => $summaries['comparison_partial'],
            'series' => ['bucket' => $bucket->value, 'points' => $page['series']],
            // Los borrados siguen en el reparto con sus horas, pero sin enlace (linkable).
            'departments' => $this->withLinks($document->breakdown($scope, 'departamentos'), Dimension::Department),
            'clients' => ['rows' => $this->withLinks($clients['rows'], Dimension::Client), 'others' => $clients['others']],
            'projects' => ['rows' => $this->withLinks($projects['rows'], Dimension::Project), 'others' => $projects['others']],
            'at_risk' => $page['at_risk'],
            'overdue' => $page['overdue'],
        ]);
    }
}
