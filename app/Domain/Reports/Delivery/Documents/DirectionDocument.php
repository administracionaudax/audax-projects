<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\HourBanksAtRisk;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\HourBankStatus;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\Department;
use App\Models\User;

/**
 * Informe de dirección (/informes/direccion, D-044): el alcance (un responsable, limitado a sus
 * departamentos), sus tablas de exportación (?tabla=clientes|proyectos|departamentos|
 * bolsas-en-riesgo|tareas-vencidas) y su PDF. Lo usan el controlador y el generador (D-139).
 *
 * @phpstan-import-type MarginRow from BuildsDashboards
 */
final class DirectionDocument extends BaseDocument
{
    use BuildsDashboards, BuildsReportScope, PdfPieces;

    public const int TOP = 10;

    public const array TABLES = ['clientes' => Dimension::Client, 'proyectos' => Dimension::Project, 'departamentos' => Dimension::Department];

    /** Listas que también se exportan enteras (en la página, las primeras). */
    public const array LISTS = ['bolsas-en-riesgo', 'tareas-vencidas'];

    private const array KPIS = ['logged', 'capacity', 'occupancy', 'billability', 'billable_productivity', 'estimation', 'income', 'margin'];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly ReportCache $cache,
        private readonly HourBanksAtRisk $atRisk,
        private readonly OverdueTasks $overdue,
    ) {}

    /**
     * Un responsable ve la dirección limitada a sus departamentos (D-044): el filtro de
     * departamento solo acota dentro de ellos y, si no deja ninguno válido, se usan todos los suyos.
     *
     * @param  array<string, mixed>  $query
     */
    public function scope(User $user, array $query): ReportScope
    {
        if ($user->isAdmin()) {
            return $this->scopeFor($user, $query);
        }

        $managed = $user->managedDepartmentIds();
        $requested = ReportFilters::fromQuery($query)->departmentIds;
        $ids = array_values(array_intersect($requested, $managed)) ?: $managed;
        sort($ids);

        return $this->scopeFor($user, $query, ['departmentIds' => $ids]);
    }

    /**
     * Reparto completo por cliente, proyecto o departamento, con margen (en caché).
     *
     * @return list<MarginRow>
     */
    public function breakdown(ReportScope $scope, string $name): array
    {
        return $this->cache->remember($scope, 'r1.direction.'.$name,
            fn (): array => $this->withMargin($this->metrics->breakdown($scope, self::TABLES[$name])));
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

    /**
     * Evolución semanal o mensual de la página (Metrics::series).
     *
     * @return list<array<string, mixed>>
     */
    public function series(ReportScope $scope, Dimension $bucket): array
    {
        return $this->metrics->series($scope, $bucket);
    }

    public function title(ReportRequest $request, User $as): string
    {
        return $this->titleFor($this->authorized($request, $as));
    }

    private function titleFor(ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.direction'), PdfFormat::period($scope->filters));
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $scope = $this->authorized($request, $as);
        $name = self::queryString($request, 'tabla');

        if ($name !== null && in_array($name, self::LISTS, true)) {
            [$headers, $lines] = $name === 'bolsas-en-riesgo'
                ? $this->atRiskTable($this->cache->remember($scope, self::daily('r1.direction.export.at_risk'), fn (): array => $this->atRisk->forScope($scope, TableExporter::MAX_ROWS)))
                : $this->overdueTable($this->cache->remember($scope, self::daily('r1.direction.export.overdue'), fn (): array => $this->overdue->forScope($scope, TableExporter::MAX_ROWS)));

            return new ExportTable(__('reports.r1.exports.direction', ['table' => __('reports.r1.tables.'.$name)]), $headers, $lines, $this->titleFor($scope));
        }

        $name = $name !== null && isset(self::TABLES[$name]) ? $name : 'clientes';
        $rows = $this->breakdown($scope, $name);
        // El reparto cubre todas las horas del alcance: su suma es el total del periodo.
        [$headers, $lines] = $this->breakdownTable(self::TABLES[$name]->label(), $rows,
            array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());

        return new ExportTable(__('reports.r1.exports.direction', ['table' => __('reports.r1.tables.'.$name)]), $headers, $lines, $this->titleFor($scope));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $scope = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $summary = $this->summaries($scope, $this->metrics, $this->cache)['summary'];
        $limited = $as->isAdmin() ? null : Department::query()->whereKey($scope->filters->departmentIds)->orderBy('name')->pluck('name')->all();
        $period = PdfFormat::period($scope->filters);
        $atRisk = $this->cache->remember($scope, self::daily('r1.direction.pdf.at_risk'), fn (): array => $this->atRisk->forScope($scope));
        $overdue = $this->cache->remember($scope, self::daily('r1.direction.pdf.overdue'), fn (): array => $this->overdue->forScope($scope, 20));
        $total = ['logged_minutes' => (int) $summary['logged_minutes'], 'billable_minutes' => (int) $summary['billable_minutes'],
            'income' => $summary['income'], 'cost' => $summary['cost'], 'margin' => $summary['margin']];

        $clients = $this->top($this->breakdown($scope, 'clientes'), self::TOP);
        $projects = $this->top($this->breakdown($scope, 'proyectos'), self::TOP);

        return new ReportPdf(
            view: 'reports.pdf.direction',
            title: $this->titleFor($scope),
            filename: self::filename(self::t('report_pdf.files.direction'), PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(
                    self::t('report_pdf.kinds.direction'),
                    $limited === null ? self::t('report_pdf.direction.agency') : implode(', ', $limited),
                    $period,
                    self::filterFacts($scope->filters, $as->isAdmin() ? [] : ['departamento']),
                    $as,
                    $financials,
                    $limited === null ? null : self::t('report_pdf.direction.limited'),
                ),
                'kpis' => self::kpis($summary, self::KPIS, $financials),
                'departments' => self::breakdownPdf(Dimension::Department->label(), $this->breakdown($scope, 'departamentos'), null, $total, $financials),
                'clients' => self::breakdownPdf(Dimension::Client->label(), $clients['rows'], $clients['others'], $total, $financials),
                'projects' => self::breakdownPdf(Dimension::Project->label(), $projects['rows'], $projects['others'], $total, $financials),
                'at_risk' => $this->atRiskPdf($atRisk),
                'at_risk_threshold' => $atRisk['threshold'],
                'overdue' => $this->overduePdf($overdue),
                'overdue_more' => max($overdue['count'] - count($overdue['tasks']), 0),
                'definitions' => self::definitions(self::KPIS, $financials),
            ],
        );
    }

    private function authorized(ReportRequest $request, User $as): ReportScope
    {
        self::authorizeFor($as, 'viewDirectionReport', Department::class);

        return $this->scope($as, $request->query);
    }

    /**
     * @param  array{banks: list<array{name: string, status: string, project: array{code: string, name: string}, client: string|null,
     *     total_minutes: int, consumed_minutes: int, overage_minutes: int, committed_minutes: int, ratio: float}>}  $atRisk
     * @return array<string, mixed>
     */
    private function atRiskPdf(array $atRisk): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.bank')], [self::t('report_pdf.columns.project')], [self::t('report_pdf.columns.status')],
                [self::t('report_pdf.columns.contracted'), true], [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true],
                [self::t('report_pdf.columns.consumed_pct'), true]],
            array_map(fn (array $bank): array => [
                $bank['name'],
                $bank['project']['code'].' · '.$bank['project']['name'].($bank['client'] !== null ? ' ('.$bank['client'].')' : ''),
                HourBankStatus::from($bank['status'])->label(),
                PdfFormat::minutes($bank['total_minutes']),
                PdfFormat::minutes($bank['consumed_minutes'] - $bank['overage_minutes']),
                $bank['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($bank['overage_minutes']), 'overage') : PdfFormat::overage(0),
                PdfFormat::percent($bank['ratio'], 0),
            ], $atRisk['banks']),
            empty: self::t('report_pdf.direction.no_banks_at_risk'),
        );
    }

    /**
     * @param  array{tasks: list<array{title: string, project: array{code: string, name: string}, assignee: string|null,
     *     due_date: string, days_overdue: int, is_milestone: bool}>}  $overdue
     * @return array<string, mixed>
     */
    private function overduePdf(array $overdue): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.task')], [self::t('report_pdf.columns.project')], [self::t('report_pdf.columns.assignee')],
                [self::t('report_pdf.columns.due_date'), true], [self::t('report_pdf.columns.days_overdue'), true]],
            array_map(fn (array $task): array => [
                ($task['is_milestone'] ? '◆ ' : '').$task['title'],
                $task['project']['code'],
                $task['assignee'] ?? self::t('report_pdf.unassigned'),
                PdfFormat::date($task['due_date']),
                (string) $task['days_overdue'],
            ], $overdue['tasks']),
            empty: self::t('report_pdf.direction.no_overdue'),
        );
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
}
