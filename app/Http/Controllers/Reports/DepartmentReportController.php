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
 *   (activas o con horas en el periodo), también las que no han imputado nada,
 * - reparto por cliente y «Carga futura» (Fase 3),
 * - ?formato=xlsx|csv exporta la tabla de miembros (con ingreso, coste y margen si hay permiso).
 *
 * @phpstan-type Member array{id: int, name: string, is_active: bool, capacity_minutes: int, logged_minutes: int,
 *     billable_minutes: int, occupancy: float|null, billability: float|null, billable_productivity: float|null,
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
        $members = $cache->remember($scope, 'r1.department.members', fn (): array => $this->members($scope, $metrics));

        $format = $this->exportFormat($request);
        if ($format !== null) {
            [$headers, $rows] = $this->membersTable($members, $scope->canSeeFinancials());

            return $exporter->download(__('reports.r1.exports.department', ['department' => $department->name]), $headers, $rows, $format);
        }

        $clients = $cache->remember($scope, 'r1.department.clients', fn (): array => $this->withMargin($metrics->breakdown($scope, Dimension::Client)));
        ['summary' => $summary, 'comparison' => $comparison] = $this->summaries($scope, $metrics, $cache);

        return Inertia::render('reports/department', [
            'department' => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color],
            'filters' => $this->filterPropsWithout($scope, ['departamento']),
            'summary' => $summary,
            'comparison' => $comparison,
            'members' => $members,
            'clients' => $this->top($clients, self::TOP),
            'occupancy_thresholds' => [
                'low' => (int) Setting::get('occupancy_low_threshold', 70),
                'high' => (int) Setting::get('occupancy_high_threshold', 110),
            ],
        ]);
    }

    /**
     * Cifras de cada persona del alcance: capacidad (Metrics::capacityByPerson) e imputadas y
     * facturables (Metrics::breakdown por persona), de más a menos horas.
     *
     * @return list<Member>
     */
    private function members(ReportScope $scope, Metrics $metrics): array
    {
        $capacity = $metrics->capacityByPerson($scope);
        $hours = collect($metrics->breakdown($scope, Dimension::Person))->keyBy('key');
        $members = [];

        foreach ($scope->people() as $person) {
            /** @var User $person */
            $row = $hours->get((string) $person->id);
            $capacityMinutes = array_sum($capacity[$person->id] ?? []);
            $logged = (int) ($row['logged_minutes'] ?? 0);
            $billable = (int) ($row['billable_minutes'] ?? 0);
            $income = $scope->canSeeFinancials() ? ($row['income'] ?? '0.00') : null;
            $cost = $scope->canSeeFinancials() ? ($row['cost'] ?? '0.00') : null;

            $members[] = [
                'id' => $person->id,
                'name' => $person->name,
                'is_active' => $person->is_active,
                'capacity_minutes' => $capacityMinutes,
                'logged_minutes' => $logged,
                'billable_minutes' => $billable,
                'occupancy' => Metrics::ratio($logged, $capacityMinutes),
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
     * @param  list<Member>  $members
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function membersTable(array $members, bool $financials): array
    {
        $headers = [
            __('reports.r1.columns.person'),
            __('reports.r1.columns.capacity'),
            __('reports.r1.columns.logged'),
            __('reports.r1.columns.billable'),
            __('reports.r1.columns.occupancy'),
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
                TableExporter::hours($member['logged_minutes']),
                TableExporter::hours($member['billable_minutes']),
                self::percent($member['occupancy']),
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
