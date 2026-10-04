<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Delivery\Documents\PersonDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Dashboard de una persona (/informes/personas/{user}, SPEC §10.5, D-044): la propia persona,
 * quien la supervisa (responsable de su departamento) o un admin; el resto, 403.
 * - capacidad, imputadas, facturables, ocupación, facturabilidad y precisión de estimación (con
 *   variación si comparar=1; en un periodo en curso, frente a los mismos días del anterior) y la
 *   capacidad transcurrida hasta ayer como dato informativo; ingreso, coste y margen solo con
 *   view-financials,
 * - reparto por cliente, proyecto y tipo de tarea y calendario de calor diario del periodo (con
 *   los filtros de la URL),
 * - días sin imputar: con capacidad y sin ninguna hora (de ningún cliente ni proyecto: aquí no
 *   cuentan los filtros), hasta ayer y nunca antes de su alta (como en Inicio),
 * - ?formato=xlsx|csv exporta el detalle diario y, con tabla=clientes|proyectos|tipos, cada reparto
 *   completo o, con tabla=dias-sin-imputar, los días sin imputar (SPEC §10: cualquier tabla);
 *   ?formato=pdf e imprimir, el informe entero (Fase 9: PersonDocument, D-139 y D-140).
 */
class PersonReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope, ExportsReports;

    public const int TOP = PersonDocument::TOP;

    /** Repartos que se exportan con ?tabla= (el resto de valores, el detalle diario). */
    public const array BREAKDOWNS = PersonDocument::BREAKDOWNS;

    public function __invoke(
        Request $request,
        User $user,
        PersonDocument $document,
    ): Response|SymfonyResponse {
        Gate::authorize('viewReport', $user);

        $export = $this->exportResponse($request, ReportKind::Person);
        if ($export !== null) {
            return $export;
        }

        /** @var User $viewer */
        $viewer = $request->user();
        $scope = $document->scope($viewer, $user, $request->query());
        $days = $document->days($scope);
        $data = $document->breakdowns($scope);
        $summaries = $document->pageSummaries($scope);
        $filters = self::withComparisonRange($this->filterPropsWithout($scope, ['persona', 'departamento']), $summaries['comparison_range']);

        $user->loadMissing('department:id,name');

        return Inertia::render('reports/person', [
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => $user->is_active,
                'department' => $user->department === null ? null : [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                    'can_view' => Gate::forUser($viewer)->allows('viewReport', $user->department),
                ],
            ],
            'is_self' => $viewer->id === $user->id,
            'filters' => $filters,
            'report_request' => $this->reportRequestProp(ReportKind::Person, ['user' => $user->id], $filters['query']),
            'summary' => $summaries['summary'],
            'comparison' => $summaries['comparison'],
            'comparison_partial' => $summaries['comparison_partial'],
            'clients' => $this->top($data['clientes'], self::TOP),
            'projects' => $this->top($data['proyectos'], self::TOP),
            'types' => $this->top($data['tipos'], self::TOP),
            'days' => array_map(fn (array $day): array => ['date' => $day['bucket'], 'minutes' => $day['logged_minutes']], $days),
            'unlogged' => $document->unlogged($scope, $user, $days),
        ]);
    }
}
