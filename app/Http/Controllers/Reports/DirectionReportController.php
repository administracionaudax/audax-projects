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
use App\Enums\HourBankStatus;
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
 * - KPIs (con variación si comparar=1; en un periodo en curso, frente a los mismos días del
 *   anterior: BuildsDashboards::summaries), evolución semanal o mensual, reparto por departamento y
 *   por cliente, top 10 de clientes y proyectos, bolsas en riesgo y tareas vencidas,
 * - ingreso, coste y margen solo con view-financials (también en la exportación),
 * - ?formato=xlsx|csv&tabla=clientes|proyectos|departamentos exporta el reparto completo y
 *   tabla=bolsas-en-riesgo|tareas-vencidas, las listas enteras (SPEC §10: cualquier tabla).
 */
class DirectionReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope;

    public const int TOP = 10;

    public const array TABLES = ['clientes' => Dimension::Client, 'proyectos' => Dimension::Project, 'departamentos' => Dimension::Department];

    /** Listas que también se exportan enteras (en la página, las primeras). */
    public const array LISTS = ['bolsas-en-riesgo', 'tareas-vencidas'];

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
            if (is_string($name) && in_array($name, self::LISTS, true)) {
                [$headers, $lines] = $name === 'bolsas-en-riesgo'
                    ? $this->atRiskTable($cache->remember($scope, self::daily('r1.direction.export.at_risk'), fn (): array => $atRisk->forScope($scope, TableExporter::MAX_ROWS)))
                    : $this->overdueTable($cache->remember($scope, self::daily('r1.direction.export.overdue'), fn (): array => $overdue->forScope($scope, TableExporter::MAX_ROWS)));

                return $exporter->download(__('reports.r1.exports.direction', ['table' => __('reports.r1.tables.'.$name)]), $headers, $lines, $format);
            }

            $name = is_string($name) && isset(self::TABLES[$name]) ? $name : 'clientes';
            $rows = $table($name);
            // El reparto cubre todas las horas del alcance: su suma es el total del periodo.
            [$headers, $lines] = $this->breakdownTable(self::TABLES[$name]->label(), $rows,
                array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());

            return $exporter->download(__('reports.r1.exports.direction', ['table' => __('reports.r1.tables.'.$name)]), $headers, $lines, $format);
        }

        $summaries = $this->summaries($scope, $metrics, $cache);
        $bucket = $this->seriesBucket($scope->filters);
        // Las tareas vencidas (y sus días de retraso) dependen de hoy: el bloque lleva la fecha.
        $page = $cache->remember($scope, self::daily('r1.direction.page.'.$bucket->value), fn (): array => [
            'series' => $metrics->series($scope, $bucket),
            'at_risk' => $atRisk->forScope($scope),
            'overdue' => $overdue->forScope($scope),
        ]);

        $clients = $this->top($table('clientes'), self::TOP);
        $projects = $this->top($table('proyectos'), self::TOP);

        return Inertia::render('reports/direction', [
            'filters' => self::withComparisonRange($this->filterProps($scope), $summaries['comparison_range']),
            'limited_to' => $user->isAdmin() ? null : Department::query()
                ->whereKey($scope->filters->departmentIds)->orderBy('name')->pluck('name')->all(),
            'summary' => $summaries['summary'],
            'comparison' => $summaries['comparison'],
            'comparison_partial' => $summaries['comparison_partial'],
            'series' => ['bucket' => $bucket->value, 'points' => $page['series']],
            // Los borrados siguen en el reparto con sus horas, pero sin enlace (linkable).
            'departments' => $this->withLinks($table('departamentos'), Dimension::Department),
            'clients' => ['rows' => $this->withLinks($clients['rows'], Dimension::Client), 'others' => $clients['others']],
            'projects' => ['rows' => $this->withLinks($projects['rows'], Dimension::Project), 'others' => $projects['others']],
            'at_risk' => $page['at_risk'],
            'overdue' => $page['overdue'],
        ]);
    }

    /**
     * Bolsas en riesgo, todas (HourBanksAtRisk::forScope): bolsa, proyecto, cliente, estado, horas
     * contratadas, dentro, exceso y comprometidas, y el consumo dentro de la bolsa.
     *
     * @param  array{banks: list<array{name: string, status: string, project: array{code: string, name: string}, client: string|null,
     *     total_minutes: int, consumed_minutes: int, overage_minutes: int, committed_minutes: int, ratio: float}>}  $atRisk
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function atRiskTable(array $atRisk): array
    {
        $headers = array_map(fn (string $column): string => __('reports.r1.columns.'.$column),
            ['bank', 'project', 'client', 'status', 'contracted', 'in_bank', 'overage', 'committed', 'consumed_pct']);

        $lines = array_map(fn (array $bank): array => [
            $bank['name'],
            $bank['project']['code'].' · '.$bank['project']['name'],
            $bank['client'],
            HourBankStatus::from($bank['status'])->label(),
            TableExporter::hours($bank['total_minutes']),
            TableExporter::hours($bank['consumed_minutes'] - $bank['overage_minutes']),
            TableExporter::hours($bank['overage_minutes']),
            TableExporter::hours($bank['committed_minutes']),
            self::percent($bank['ratio']),
        ], $atRisk['banks']);

        return [$headers, $lines];
    }

    /**
     * Tareas vencidas, todas (OverdueTasks::forScope): tarea, proyecto, responsable, fecha límite,
     * días de retraso y si es un hito.
     *
     * @param  array{tasks: list<array{title: string, project: array{code: string, name: string}, assignee: string|null,
     *     due_date: string, days_overdue: int, is_milestone: bool}>}  $overdue
     * @return array{0: list<string>, 1: list<list<string|int|float|bool|null>>}
     */
    private function overdueTable(array $overdue): array
    {
        $headers = array_map(fn (string $column): string => __('reports.r1.columns.'.$column),
            ['task', 'project', 'assignee', 'due_date', 'days_overdue', 'milestone']);

        $lines = array_map(fn (array $task): array => [
            $task['title'],
            $task['project']['code'].' · '.$task['project']['name'],
            $task['assignee'] ?? __('reports.r1.columns.unassigned'),
            $task['due_date'],
            $task['days_overdue'],
            $task['is_milestone'],
        ], $overdue['tasks']);

        return [$headers, $lines];
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
