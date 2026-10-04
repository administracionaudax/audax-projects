<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Delivery\Documents\DepartmentDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Dimension;
use App\Domain\Workload\WorkloadBoard;
use App\Domain\Workload\WorkloadFilters;
use App\Domain\Workload\WorkloadHorizon;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Dashboard de un departamento (/informes/departamentos/{department}, SPEC §10.4, D-044): admin,
 * cualquiera; un responsable, los que dirige; el resto, 403.
 * - KPIs del departamento (con variación si comparar=1),
 * - ocupación y facturabilidad de cada miembro (tabla y barras): las personas del departamento
 *   (activas o con horas en el periodo), también las que no han imputado nada. La ocupación y la
 *   productividad facturable, las del SPEC §10 (contra la capacidad del periodo completo, como en
 *   el resto de informes); la capacidad transcurrida hasta ayer va aparte. En un periodo en curso,
 *   el nivel de cada miembro (baja, en rango, alta; umbrales de D-047) se mide con su ritmo:
 *   imputadas / capacidad transcurrida hasta ayer (pace; D-080). Sin capacidad transcurrida, sin
 *   ritmo ni nivel («aún sin datos»),
 * - reparto por cliente y «Carga futura» (Fase 3): la carga planificada de las próximas cuatro
 *   semanas de las personas del departamento, la misma de la vista Carga (WorkloadBoard, D-051),
 *   como prop diferida para no retrasar el informe,
 * - ?formato=xlsx|csv exporta la tabla de miembros (con ingreso, coste y margen si hay permiso) y,
 *   con tabla=clientes, el reparto por cliente completo (SPEC §10: cualquier tabla); ?formato=pdf
 *   e imprimir, el informe entero (Fase 9: DepartmentDocument, D-139 y D-140).
 *
 * @phpstan-type LoadTotals array{planned: int, capacity: int}
 * @phpstan-type FutureLoad array{columns: list<array{key: string, from: string, to: string, today: bool, weekend: bool}>,
 *     people: list<array{id: int, name: string, cells: list<LoadTotals>, total: LoadTotals}>, totals: list<LoadTotals>, total: LoadTotals, url: string}
 */
class DepartmentReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope, ExportsReports;

    public const int TOP = 10;

    public function __invoke(
        Request $request,
        Department $department,
        DepartmentDocument $document,
    ): Response|SymfonyResponse {
        Gate::authorize('viewReport', $department);

        $export = $this->exportResponse($request, ReportKind::Department);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $scope = $document->scope($user, $department, $request->query());
        $members = $document->members($scope);
        $clients = $document->clients($scope);
        $summaries = $document->pageSummaries($scope);
        $clients = $this->top($clients, self::TOP);
        $filters = self::withComparisonRange($this->filterPropsWithout($scope, ['departamento']), $summaries['comparison_range']);

        return Inertia::render('reports/department', [
            'department' => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color],
            'filters' => $filters,
            'report_request' => $this->reportRequestProp(ReportKind::Department, ['department' => $department->id], $filters['query']),
            'summary' => $summaries['summary'],
            'comparison' => $summaries['comparison'],
            'comparison_partial' => $summaries['comparison_partial'],
            'members' => $members,
            // Los clientes borrados siguen en el reparto con sus horas, pero sin enlace (linkable).
            'clients' => ['rows' => $this->withLinks($clients['rows'], Dimension::Client), 'others' => $clients['others']],
            'occupancy_thresholds' => [
                'low' => (int) Setting::get('occupancy_low_threshold', 70),
                'high' => (int) Setting::get('occupancy_high_threshold', 110),
            ],
            'future_load' => Inertia::defer(fn (): array => $this->futureLoad($scope->viewer, $department)),
        ]);
    }

    /**
     * Carga planificada de las próximas cuatro semanas (desde hoy, por semanas de lunes a domingo)
     * de las personas del departamento: el mismo reparto que /carga con ese departamento (D-051,
     * D-052; WorkloadBoard y su WorkloadPlan), sumado por semanas, sin sus tareas. No depende de los
     * filtros del informe: la carga es del futuro, no del periodo.
     *
     * @return FutureLoad
     */
    private function futureLoad(User $viewer, Department $department): array
    {
        $board = WorkloadBoard::for($viewer, new WorkloadFilters(WorkloadHorizon::FourWeeks, [$department->id]));
        $plan = $board->plan();
        $today = $board->today->toDateString();

        $columns = [];
        for ($start = $board->from; $start <= $board->to; $start = $end->addDay()) {
            $sunday = $start->addDays(7 - $start->dayOfWeekIso);
            $end = $sunday < $board->to ? $sunday : $board->to;
            $columns[] = [
                'key' => $start->toDateString(),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'today' => $start->toDateString() <= $today && $today <= $end->toDateString(),
                'weekend' => false,
            ];
        }

        $people = [];

        foreach ($board->rows() as $person) {
            $cells = array_map(fn (array $column): array => [
                'planned' => $plan->loadBetween($person->id, $column['from'], $column['to']),
                'capacity' => $plan->capacityBetween($person->id, $column['from'], $column['to']),
            ], $columns);

            $people[] = [
                'id' => $person->id,
                'name' => $person->name,
                'cells' => $cells,
                'total' => [
                    'planned' => array_sum(array_column($cells, 'planned')),
                    'capacity' => array_sum(array_column($cells, 'capacity')),
                ],
            ];
        }

        $totals = array_map(fn (int $index): array => [
            'planned' => array_sum(array_map(fn (array $person): int => $person['cells'][$index]['planned'], $people)),
            'capacity' => array_sum(array_map(fn (array $person): int => $person['cells'][$index]['capacity'], $people)),
        ], array_keys($columns));

        return [
            'columns' => $columns,
            'people' => $people,
            'totals' => $totals,
            'total' => [
                'planned' => array_sum(array_column($totals, 'planned')),
                'capacity' => array_sum(array_column($totals, 'capacity')),
            ],
            'url' => route('workload.index', ['horizonte' => WorkloadHorizon::FourWeeks->value, 'departamento' => $department->id], absolute: false),
        ];
    }
}
