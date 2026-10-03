<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Money;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\User;

/**
 * Informe de un departamento (/informes/departamentos/{department}, D-044): miembros con su
 * ocupación y facturabilidad, reparto por cliente, sus tablas de exportación (?tabla=clientes, o
 * los miembros) y su PDF. Lo usan el controlador y el generador (D-139).
 *
 * @phpstan-import-type MarginRow from BuildsDashboards
 *
 * @phpstan-type Member array{id: int, name: string, is_active: bool, capacity_minutes: int, capacity_to_date_minutes: int, logged_minutes: int,
 *     billable_minutes: int, occupancy: float|null, pace: float|null, billability: float|null, billable_productivity: float|null,
 *     income: string|null, cost: string|null, margin: string|null}
 */
final class DepartmentDocument extends BaseDocument
{
    use BuildsDashboards, BuildsReportScope, PdfPieces;

    public const int TOP = 10;

    private const array KPIS = ['logged', 'capacity', 'occupancy', 'billability', 'billable_productivity', 'estimation', 'income', 'margin'];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly ReportCache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function scope(User $user, Department $department, array $query): ReportScope
    {
        return $this->scopeFor($user, $query, ['departmentIds' => [$department->id]]);
    }

    /**
     * Reparto completo por cliente, con margen (en caché).
     *
     * @return list<MarginRow>
     */
    public function clients(ReportScope $scope): array
    {
        return $this->cache->remember($scope, 'r1.department.clients', fn (): array => $this->withMargin($this->metrics->breakdown($scope, Dimension::Client)));
    }

    /**
     * Miembros del departamento con sus cifras (en caché del día).
     *
     * @return list<Member>
     */
    public function members(ReportScope $scope): array
    {
        return $this->cache->remember($scope, self::daily('r1.department.members'), fn (): array => $this->computeMembers($scope));
    }

    /**
     * Resumen y comparación de la página (BuildsDashboards::summaries) con la Metrics del
     * documento: la misma memoria de capacidad que el resto de bloques (sin consultas repetidas).
     *
     * @return array{summary: array<string, mixed>, comparison: array<string, mixed>|null,
     *     comparison_range: array{from: string, to: string}|null, comparison_partial: bool}
     */
    public function pageSummaries(ReportScope $scope): array
    {
        return $this->summaries($scope, $this->metrics, $this->cache);
    }

    public function title(ReportRequest $request, User $as): string
    {
        [$department, $scope] = $this->authorized($request, $as);

        return $this->titleFor($department, $scope);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$department, $scope] = $this->authorized($request, $as);
        $title = $this->titleFor($department, $scope);

        if (self::queryString($request, 'tabla') === 'clientes') {
            $rows = $this->clients($scope);
            [$headers, $lines] = $this->breakdownTable(Dimension::Client->label(), $rows, array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());

            return new ExportTable(__('reports.r1.exports.department_table', ['department' => $department->name, 'table' => __('reports.r1.tables.clientes')]), $headers, $lines, $title);
        }

        [$headers, $rows] = $this->membersTable($this->members($scope), $scope->canSeeFinancials());

        return new ExportTable(__('reports.r1.exports.department', ['department' => $department->name]), $headers, $rows, $title);
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$department, $scope] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $summary = $this->summaries($scope, $this->metrics, $this->cache)['summary'];
        $members = $this->members($scope);
        $clients = $this->top($this->clients($scope), self::TOP);
        $total = ['logged_minutes' => (int) $summary['logged_minutes'], 'billable_minutes' => (int) $summary['billable_minutes'],
            'income' => $summary['income'], 'cost' => $summary['cost'], 'margin' => $summary['margin']];

        return new ReportPdf(
            view: 'reports.pdf.department',
            title: $this->titleFor($department, $scope),
            filename: self::filename(self::t('report_pdf.files.department'), $department->name, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.department'), $department->name, PdfFormat::period($scope->filters),
                    self::filterFacts($scope->filters, ['departamento']), $as, $financials),
                'kpis' => self::kpis($summary, self::KPIS, $financials),
                'members' => $this->membersPdf($members, $financials),
                'clients' => self::breakdownPdf(Dimension::Client->label(), $clients['rows'], $clients['others'], $total, $financials),
                'definitions' => self::definitions([...self::KPIS, 'pace'], $financials),
            ],
        );
    }

    /**
     * @return array{0: Department, 1: ReportScope}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $department = self::routeModel($request, 'department', Department::class);
        self::authorizeFor($as, 'viewReport', $department);

        return [$department, $this->scope($as, $department, $request->query)];
    }

    private function titleFor(Department $department, ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.department'), $department->name, PdfFormat::period($scope->filters));
    }

    /**
     * @param  list<Member>  $members
     * @return array<string, mixed>
     */
    private function membersPdf(array $members, bool $financials): array
    {
        $inProgress = array_sum(array_column($members, 'capacity_to_date_minutes')) < array_sum(array_column($members, 'capacity_minutes'));
        $columns = [[self::t('report_pdf.columns.person')], [self::t('report_pdf.columns.capacity'), true], [self::t('report_pdf.columns.logged'), true],
            [self::t('report_pdf.columns.billable'), true], [self::t('report_pdf.columns.occupancy'), true],
            ...($inProgress ? [[self::t('report_pdf.columns.pace'), true]] : []),
            [self::t('report_pdf.columns.billability'), true]];
        if ($financials) {
            array_push($columns, [self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.margin'), true]);
        }

        $rows = array_map(fn (array $member): array => [
            $member['name'].($member['is_active'] ? '' : ' '.self::t('report_pdf.inactive')),
            PdfFormat::minutes($member['capacity_minutes']),
            PdfFormat::minutes($member['logged_minutes']),
            PdfFormat::minutes($member['billable_minutes']),
            PdfFormat::percent($member['occupancy']),
            ...($inProgress ? [PdfFormat::percent($member['pace'])] : []),
            PdfFormat::percent($member['billability']),
            ...($financials ? [PdfFormat::money($member['income']), PdfFormat::money($member['margin'])] : []),
        ], $members);

        return PdfTable::make($columns, $rows, empty: self::t('report_pdf.department.no_members'));
    }

    /**
     * Cifras de cada persona del alcance: capacidad del periodo y transcurrida hasta ayer
     * (Metrics::capacityTotalsByPerson y elapsedCapacityByPerson) e imputadas y facturables
     * (Metrics::breakdown por persona), de más a menos horas. La ocupación y la productividad
     * facturable, contra la capacidad del periodo (SPEC §10, como Metrics::summary). El ritmo
     * (pace, D-080), solo si al periodo aún le quedan días con jornada: imputadas / capacidad
     * transcurrida hasta ayer, o null si aún no ha pasado ninguno.
     *
     * @return list<Member>
     */
    private function computeMembers(ReportScope $scope): array
    {
        $capacity = $this->metrics->capacityTotalsByPerson($scope);
        $elapsed = $this->metrics->elapsedCapacityByPerson($scope);
        $hours = collect($this->metrics->breakdown($scope, Dimension::Person))->keyBy('key');
        $members = [];

        foreach ($scope->people() as $person) {
            /** @var User $person */
            $row = $hours->get((string) $person->id);
            $capacityMinutes = $capacity[$person->id] ?? 0;
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
