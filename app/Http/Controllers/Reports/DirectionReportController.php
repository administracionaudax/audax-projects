<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\HourBanksAtRisk;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard de dirección (/informes/direccion, SPEC §10.1, D-044):
 * - admin: toda la agencia; responsable: limitado a los departamentos que dirige (se le imponen
 *   siempre: con el filtro de departamento solo puede acotar dentro de los suyos); el resto, 403,
 * - KPIs (con variación si comparar=1), evolución semanal o mensual, reparto por departamento y
 *   por cliente, top 10 de clientes y proyectos, bolsas en riesgo y tareas vencidas,
 * - ingreso, coste y margen solo con view-financials (también en la exportación),
 * - ?formato=xlsx|csv&tabla=clientes|proyectos|departamentos exporta el reparto completo.
 */
class DirectionReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope;

    public const int TOP = 10;

    public const array TABLES = ['clientes' => Dimension::Client, 'proyectos' => Dimension::Project, 'departamentos' => Dimension::Department];

    public function __invoke(
        Request $request,
        Metrics $metrics,
        ReportCache $cache,
        HourBanksAtRisk $atRisk,
        OverdueTasks $overdue,
        TableExporter $exporter,
    ): Response|StreamedResponse {
        Gate::authorize('viewDirectionReport', Department::class);

        /** @var User $user */
        $user = $request->user();
        $scope = $this->directionScope($request, $user);
        $table = fn (string $name): array => $cache->remember($scope, 'r1.direction.'.$name,
            fn (): array => $this->withMargin($metrics->breakdown($scope, self::TABLES[$name])));

        $format = $this->exportFormat($request);
        if ($format !== null) {
            $name = $request->query('tabla');
            $name = is_string($name) && isset(self::TABLES[$name]) ? $name : 'clientes';
            $rows = $table($name);
            // El reparto cubre todas las horas del alcance: su suma es el total del periodo.
            [$headers, $lines] = $this->breakdownTable(self::TABLES[$name]->label(), $rows,
                array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());

            return $exporter->download(__('reports.r1.exports.direction', ['table' => __('reports.r1.tables.'.$name)]), $headers, $lines, $format);
        }

        ['summary' => $summary, 'comparison' => $comparison] = $this->summaries($scope, $metrics, $cache);
        $bucket = $this->seriesBucket($scope->filters);
        $page = $cache->remember($scope, 'r1.direction.page.'.$bucket->value, fn (): array => [
            'series' => $metrics->series($scope, $bucket),
            'at_risk' => $atRisk->forScope($scope),
            'overdue' => $overdue->forScope($scope),
        ]);

        return Inertia::render('reports/direction', [
            'filters' => $this->filterProps($scope),
            'limited_to' => $user->isAdmin() ? null : Department::query()
                ->whereKey($scope->filters->departmentIds)->orderBy('name')->pluck('name')->all(),
            'summary' => $summary,
            'comparison' => $comparison,
            'series' => ['bucket' => $bucket->value, 'points' => $page['series']],
            'departments' => $table('departamentos'),
            'clients' => $this->top($table('clientes'), self::TOP),
            'projects' => $this->top($table('proyectos'), self::TOP),
            'at_risk' => $page['at_risk'],
            'overdue' => $page['overdue'],
        ]);
    }

    /**
     * Un responsable ve la dirección limitada a sus departamentos (D-044): el filtro de
     * departamento solo acota dentro de ellos y, si no deja ninguno válido, se usan todos los suyos.
     */
    private function directionScope(Request $request, User $user): ReportScope
    {
        if ($user->isAdmin()) {
            return $this->reportScope($request);
        }

        $managed = $user->managedDepartmentIds();
        $requested = ReportFilters::fromQuery($request->query())->departmentIds;
        $ids = array_values(array_intersect($requested, $managed)) ?: $managed;
        sort($ids);

        return $this->reportScope($request, ['departmentIds' => $ids]);
    }
}
