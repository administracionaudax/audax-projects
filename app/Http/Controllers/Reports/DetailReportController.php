<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ComparisonPeriod;
use App\Domain\Reports\Delivery\Documents\DetailDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Informe de horas detallado /informes/detalle (SPEC §10.6): tabla dinámica por dos dimensiones
 * cualesquiera con subtotales (PivotReport), para cualquier interno con su alcance (D-044: cada uno
 * ve lo suyo). Sin datos económicos: solo horas.
 *
 * URL: los filtros globales (ReportFilters) más filas=, columnas= (Dimension) y medida=
 * imputadas|facturables|dentro|exceso. Los valores que no se entienden se ignoran. Con
 * ?formato=xlsx|csv exporta la tabla tal cual, con los subtotales (otro valor muestra la página), y
 * ?formato=pdf o imprimir, el informe (Fase 9: DetailDocument, D-139 y D-140).
 * Con comparar=1, los KPIs de horas del periodo anterior; en un periodo en curso, de sus mismos
 * días transcurridos (ComparisonPeriod, D-079).
 */
class DetailReportController extends Controller
{
    use BuildsReportScope, ExportsReports;

    public const array DIMENSIONS = DetailDocument::DIMENSIONS;

    public const array MEASURES = DetailDocument::MEASURES;

    public const string DEFAULT_MEASURE = DetailDocument::DEFAULT_MEASURE;

    /**
     * Filtros globales de la barra, en su orden (ReportFilterKey en resources/js/types/reports.ts).
     */
    public const array FILTERS = ['persona', 'departamento', 'cliente', 'proyecto', 'bolsa', 'tipo', 'facturable'];

    public function __invoke(Request $request, DetailDocument $document): Response|SymfonyResponse
    {
        Gate::authorize('viewDetailReport', TimeEntry::class);

        $export = $this->exportResponse($request, ReportKind::Detail);
        if ($export !== null) {
            return $export;
        }

        $scope = $this->reportScope($request);
        $dimensions = $document->dimensions($scope->viewer);
        [$rows, $columns, $measure] = $document->layout($request->query(), $dimensions);
        $result = $document->result($scope, $rows, $columns, $measure);

        $layout = ['filas' => $rows->value, 'columnas' => $columns->value, 'medida' => $measure];
        $filters = $this->filterProps($scope);

        // Las elecciones de la tabla viajan con los filtros: al cambiar de periodo o de filtro se conservan.
        foreach (['query', 'previous', 'next'] as $key) {
            $filters[$key] = [...$filters[$key], ...$layout];
        }

        // Comparar: en un periodo en curso, con los mismos días del anterior (D-079), como el resto de dashboards.
        $comparison = ComparisonPeriod::hoursScope($scope);

        return Inertia::render('reports/detail', [
            'filters' => ComparisonPeriod::withRange($filters, $comparison['range'] ?? null),
            'report_request' => $this->reportRequestProp(ReportKind::Detail, [], $filters['query']),
            'layout' => $layout,
            'dimensions' => array_map(fn (Dimension $dimension): string => $dimension->value, $dimensions),
            'filterKeys' => $this->filterKeys($scope),
            'measures' => array_keys(self::MEASURES),
            'pivot' => $result,
            'summary' => $document->summary($scope),
            'comparison' => $comparison !== null ? $document->summary($comparison['scope']) : null,
        ]);
    }

    /**
     * Filtros de la barra (ReportFilterBar): persona y departamento solo para quien tiene equipo
     * (admin o responsable de algún departamento), que son los que tienen opciones que elegir
     * (ReportOptionsController). Un gestor ve las horas de sus proyectos y los filtra por proyecto;
     * un empleado solo se ve a sí mismo.
     *
     * @return list<string>
     */
    private function filterKeys(ReportScope $scope): array
    {
        $viewer = $scope->viewer;
        $team = $viewer->isAdmin() || $viewer->managedDepartmentIds() !== [];

        return array_values(array_filter(self::FILTERS, fn (string $key): bool => $team || ! in_array($key, ['persona', 'departamento'], true)));
    }
}
