<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Money;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * - reparto por cliente y «Carga futura» (Fase 3),
 * - ?formato=xlsx|csv exporta la tabla de miembros (con ingreso, coste y margen si hay permiso) y,
 *   con tabla=clientes, el reparto por cliente completo (SPEC §10: cualquier tabla).
 *
 * @phpstan-type Member array{id: int, name: string, is_active: bool, capacity_minutes: int, capacity_to_date_minutes: int, logged_minutes: int,
 *     billable_minutes: int, occupancy: float|null, pace: float|null, billability: float|null, billable_productivity: float|null,
 *     income: string|null, cost: string|null, margin: string|null}
 */
class DepartmentReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope;

    public const int TOP = 10;

    public function __invoke(
        Request $request,
        Department $department,
        Metrics $metrics,
        ReportCache $cache,
        TableExporter $exporter,
    ): Response|StreamedResponse {
        Gate::authorize('viewReport', $department);

        $scope = $this->reportScope($request, ['departmentIds' => [$department->id]]);
        $clients = fn (): array => $cache->remember($scope, 'r1.department.clients', fn (): array => $this->withMargin($metrics->breakdown($scope, Dimension::Client)));

        $format = $this->exportFormat($request);
        if ($format !== null && $request->query('tabla') === 'clientes') {
            $rows = $clients();
            [$headers, $lines] = $this->breakdownTable(Dimension::Client->label(), $rows, array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());

            return $exporter->download(__('reports.r1.exports.department_table', ['department' => $department->name, 'table' => __('reports.r1.tables.clientes')]), $headers, $lines, $format);
        }

        $members = $cache->remember($scope, self::daily('r1.department.members'), fn (): array => $this->members($scope, $metrics));

        if ($format !== null) {
            [$headers, $rows] = $this->membersTable($members, $scope->canSeeFinancials());

            return $exporter->download(__('reports.r1.exports.department', ['department' => $department->name]), $headers, $rows, $format);
        }

        $clients = $clients();
        $summaries = $this->summaries($scope, $metrics, $cache);
        $clients = $this->top($clients, self::TOP);

        return Inertia::render('reports/department', [
            'department' => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color],
            'filters' => self::withComparisonRange($this->filterPropsWithout($scope, ['departamento']), $summaries['comparison_range']),
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
        ]);
    }

    /**
     * Cifras de cada persona del alcance: capacidad del periodo y transcurrida hasta ayer
     * (Metrics::capacityByPerson y elapsedCapacityByPerson) e imputadas y facturables
     * (Metrics::breakdown por persona), de más a menos horas. La ocupación y la productividad
     * facturable, contra la capacidad del periodo (SPEC §10, como Metrics::summary). El ritmo
     * (pace, D-080), solo si al periodo aún le quedan días con jornada: imputadas / capacidad
     * transcurrida hasta ayer, o null si aún no ha pasado ninguno.
     *
     * @return list<Member>
     */
    private function members(ReportScope $scope, Metrics $metrics): array
    {
        $capacity = $metrics->capacityByPerson($scope);
        $elapsed = $metrics->elapsedCapacityByPerson($scope);
        $hours = collect($metrics->breakdown($scope, Dimension::Person))->keyBy('key');
        $members = [];

        foreach ($scope->people() as $person) {
            /** @var User $person */
            $row = $hours->get((string) $person->id);
            $capacityMinutes = array_sum($capacity[$person->id] ?? []);
            $toDate = $elapsed[$person->id] ?? 0;
            $logged = (int) ($row['logged_minutes'] ?? 0);
            $billable = (int) ($row['billable_minutes'] ?? 0);
            $income = $scope->canSeeFinancials() ? ($row['income'] ?? '0.00') : null;
            $cost = $scope->canSeeFinancials() ? ($row['cost'] ?? '0.00') : null;

            $members[] = [
                'id' => $person->id,
                'name' => $person->name,
                'is_active' => $person->is_active,
                'capacity_minutes' => $capacityMinutes,
                'capacity_to_date_minutes' => $toDate,
                'logged_minutes' => $logged,
                'billable_minutes' => $billable,
                'occupancy' => Metrics::ratio($logged, $capacityMinutes),
                'pace' => $toDate < $capacityMinutes ? Metrics::ratio($logged, $toDate) : null,
                'billability' => Metrics::ratio($billable, $logged),
                'billable_productivity' => Metrics::ratio($billable, $capacityMinutes),
                'income' => $income,
                'cost' => $cost,
                'margin' => $income === null ? null : Money::round(Money::sub($income, (string) $cost)),
            ];
        }

        usort($members, fn (array $a, array $b): int => [$b['logged_minutes'], $a['name']] <=> [$a['logged_minutes'], $b['name']]);

        return $members;
    }

    /**
     * Tabla de miembros para exportar. Si al periodo aún le quedan días con jornada, lleva además
     * la capacidad transcurrida hasta ayer y el ritmo (imputadas / esa capacidad, D-080), como la
     * tabla de la página.
     *
     * @param  list<Member>  $members
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function membersTable(array $members, bool $financials): array
    {
        $inProgress = array_sum(array_column($members, 'capacity_to_date_minutes')) < array_sum(array_column($members, 'capacity_minutes'));
        $headers = [
            __('reports.r1.columns.person'),
            __('reports.r1.columns.capacity'),
            ...($inProgress ? [__('reports.r1.columns.capacity_to_date')] : []),
            __('reports.r1.columns.logged'),
            __('reports.r1.columns.billable'),
            __('reports.r1.columns.occupancy'),
            ...($inProgress ? [__('reports.r1.columns.pace')] : []),
            __('reports.r1.columns.billability'),
            __('reports.r1.columns.billable_productivity'),
        ];

        if ($financials) {
            array_push($headers, __('reports.r1.columns.income'), __('reports.r1.columns.cost'), __('reports.r1.columns.margin'));
        }

        $rows = [];
        foreach ($members as $member) {
            $line = [
                $member['name'],
                TableExporter::hours($member['capacity_minutes']),
                ...($inProgress ? [TableExporter::hours($member['capacity_to_date_minutes'])] : []),
                TableExporter::hours($member['logged_minutes']),
                TableExporter::hours($member['billable_minutes']),
                self::percent($member['occupancy']),
                ...($inProgress ? [self::percent($member['pace'])] : []),
                self::percent($member['billability']),
                self::percent($member['billable_productivity']),
            ];

            if ($financials) {
                array_push($line, TableExporter::money($member['income']), TableExporter::money($member['cost']), TableExporter::money($member['margin']));
            }

            $rows[] = $line;
        }

        return [$headers, $rows];
    }
}
