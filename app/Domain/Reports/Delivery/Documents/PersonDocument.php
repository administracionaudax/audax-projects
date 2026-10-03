<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Time\Week;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Informe de una persona (/informes/personas/{user}, D-044): detalle diario, repartos por cliente,
 * proyecto y tipo, días sin imputar, sus tablas de exportación (?tabla=clientes|proyectos|tipos|
 * dias-sin-imputar, o el detalle diario) y su PDF. Lo usan el controlador y el generador (D-139).
 *
 * @phpstan-import-type MarginRow from BuildsDashboards
 *
 * @phpstan-type DayPoint array{bucket: string, logged_minutes: int, billable_minutes: int, capacity_minutes: int, income: string|null}
 */
final class PersonDocument extends BaseDocument
{
    use BuildsDashboards, BuildsReportScope, PdfPieces;

    public const int TOP = 8;

    /** Repartos que se exportan con ?tabla= (el resto de valores, el detalle diario). */
    public const array BREAKDOWNS = ['clientes' => Dimension::Client, 'proyectos' => Dimension::Project, 'tipos' => Dimension::TaskType];

    /** El PDF lleva el detalle diario hasta dos meses; más, sería una lista de cientos de días. */
    public const int PDF_DAYS = 62;

    private const array KPIS = ['capacity', 'logged', 'billable', 'occupancy', 'billability', 'estimation', 'income', 'margin'];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly ReportCache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function scope(User $viewer, User $person, array $query): ReportScope
    {
        return $this->scopeFor($viewer, $query, ['userIds' => [$person->id], 'departmentIds' => []]);
    }

    /**
     * @return list<DayPoint>
     */
    public function days(ReportScope $scope): array
    {
        return $this->cache->remember($scope, 'r1.person.days', fn (): array => $this->metrics->series($scope, Dimension::Day));
    }

    /**
     * @return array{clientes: list<MarginRow>, proyectos: list<MarginRow>, tipos: list<MarginRow>}
     */
    public function breakdowns(ReportScope $scope): array
    {
        return $this->cache->remember($scope, 'r1.person.breakdowns', fn (): array => [
            'clientes' => $this->withMargin($this->metrics->breakdown($scope, Dimension::Client)),
            'proyectos' => $this->withMargin($this->metrics->breakdown($scope, Dimension::Project)),
            'tipos' => $this->withMargin($this->metrics->breakdown($scope, Dimension::TaskType)),
        ]);
    }

    /**
     * Días con capacidad y sin ninguna hora (de ningún cliente ni proyecto), hasta ayer y nunca
     * antes de su alta (como la tarjeta de Inicio).
     *
     * @param  list<DayPoint>  $days  las del periodo con los filtros de la URL
     * @return list<array{date: string, capacity_minutes: int, week: string}>
     */
    public function unlogged(ReportScope $scope, User $person, array $days): array
    {
        return $this->unloggedDays($person, $this->allHours($scope, $person, $days));
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
        [$person, $scope] = $this->authorized($request, $as);

        return $this->titleFor($person, $scope);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$user, $scope] = $this->authorized($request, $as);
        $days = $this->days($scope);
        $table = self::queryString($request, 'tabla');
        $name = __('reports.r1.exports.person', ['person' => $user->name]);

        if ($table !== null && isset(self::BREAKDOWNS[$table])) {
            $rows = $this->breakdowns($scope)[$table];
            [$headers, $lines] = $this->breakdownTable(self::BREAKDOWNS[$table]->label(), $rows, array_sum(array_column($rows, 'logged_minutes')), $scope->canSeeFinancials());
            $name = __('reports.r1.exports.person_table', ['person' => $user->name, 'table' => __('reports.r1.tables.'.$table)]);
        } elseif ($table === 'dias-sin-imputar') {
            [$headers, $lines] = $this->unloggedTable($this->unlogged($scope, $user, $days));
            $name = __('reports.r1.exports.person_table', ['person' => $user->name, 'table' => __('reports.r1.tables.dias-sin-imputar')]);
        } else {
            [$headers, $lines] = $this->daysTable($days, $scope->canSeeFinancials());
        }

        return new ExportTable($name, $headers, $lines, $this->titleFor($user, $scope));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$person, $scope] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $summary = $this->summaries($scope, $this->metrics, $this->cache)['summary'];
        $days = $this->days($scope);
        $breakdowns = $this->breakdowns($scope);
        $unlogged = $this->unlogged($scope, $person, $days);
        $total = ['logged_minutes' => (int) $summary['logged_minutes'], 'billable_minutes' => (int) $summary['billable_minutes'],
            'income' => $summary['income'], 'cost' => $summary['cost'], 'margin' => $summary['margin']];
        $top = fn (string $name): array => $this->top($breakdowns[$name], self::TOP);
        $person->loadMissing('department:id,name');

        return new ReportPdf(
            view: 'reports.pdf.person',
            title: $this->titleFor($person, $scope),
            filename: self::filename(self::t('report_pdf.files.person'), $person->name, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.person'), $person->name, PdfFormat::period($scope->filters),
                    [...($person->department !== null ? [[self::t('report_pdf.filters.departamento'), $person->department->name]] : []),
                        ...self::filterFacts($scope->filters, ['persona', 'departamento'])],
                    $as, $financials),
                'kpis' => self::kpis($summary, self::KPIS, $financials),
                'clients' => self::breakdownPdf(Dimension::Client->label(), $top('clientes')['rows'], $top('clientes')['others'], $total, $financials),
                'projects' => self::breakdownPdf(Dimension::Project->label(), $top('proyectos')['rows'], $top('proyectos')['others'], $total, $financials),
                'types' => self::breakdownPdf(Dimension::TaskType->label(), $top('tipos')['rows'], $top('tipos')['others'], $total, $financials),
                'unlogged' => PdfTable::make(
                    [[self::t('report_pdf.columns.date')], [self::t('report_pdf.columns.weekday')], [self::t('report_pdf.columns.day_capacity'), true]],
                    array_map(fn (array $day): array => [
                        PdfFormat::date($day['date']),
                        __('reports.r1.weekdays.'.CarbonImmutable::parse($day['date'])->dayOfWeekIso),
                        PdfFormat::minutes($day['capacity_minutes']),
                    ], $unlogged),
                    empty: self::t('report_pdf.person.no_unlogged'),
                ),
                'days' => $scope->filters->days() <= self::PDF_DAYS ? $this->daysPdf($days, $financials) : null,
                'definitions' => self::definitions(self::KPIS, $financials),
            ],
        );
    }

    /**
     * @return array{0: User, 1: ReportScope}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $person = self::routeModel($request, 'user', User::class);
        self::authorizeFor($as, 'viewReport', $person);

        return [$person, $this->scope($as, $person, $request->query)];
    }

    private function titleFor(User $person, ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.person'), $person->name, PdfFormat::period($scope->filters));
    }

    /**
     * @param  list<DayPoint>  $days
     * @return array<string, mixed>
     */
    private function daysPdf(array $days, bool $financials): array
    {
        $today = LocalTime::todayString();
        $columns = [[self::t('report_pdf.columns.date')], [self::t('report_pdf.columns.weekday')], [self::t('report_pdf.columns.capacity'), true],
            [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true], [self::t('report_pdf.columns.occupancy'), true]];
        if ($financials) {
            $columns[] = [self::t('report_pdf.columns.income'), true];
        }

        $rows = array_map(fn (array $day): array => [
            PdfFormat::date($day['bucket']),
            __('reports.r1.weekdays.'.CarbonImmutable::parse($day['bucket'])->dayOfWeekIso),
            PdfFormat::minutes($day['capacity_minutes']),
            PdfFormat::minutes($day['logged_minutes']),
            PdfFormat::minutes($day['billable_minutes']),
            $day['bucket'] > $today ? '' : PdfFormat::percent(Metrics::ratio($day['logged_minutes'], $day['capacity_minutes']), 0),
            ...($financials ? [PdfFormat::money($day['income'])] : []),
        ], $days);

        $sum = [self::t('report_pdf.total'), '', PdfFormat::minutes(array_sum(array_column($days, 'capacity_minutes'))),
            PdfFormat::minutes(array_sum(array_column($days, 'logged_minutes'))), PdfFormat::minutes(array_sum(array_column($days, 'billable_minutes'))), '',
            ...($financials ? [''] : [])];

        return PdfTable::make($columns, $rows, $sum, compact: true);
    }

    /**
     * Horas por día de la persona en el periodo sin los demás filtros (para los días sin imputar
     * cuenta cualquier hora). Si la URL no filtra nada más, son las mismas que las del calendario.
     *
     * @param  list<DayPoint>  $filtered
     * @return list<DayPoint>
     */
    private function allHours(ReportScope $scope, User $user, array $filtered): array
    {
        $f = $scope->filters;

        if ($f->clientIds === [] && $f->projectIds === [] && $f->bankIds === [] && $f->taskTypeIds === [] && $f->billable === null) {
            return $filtered;
        }

        $plain = $scope->withFilters(new ReportFilters($f->period, $f->from, $f->to, userIds: [$user->id]))->withoutFinancials();

        return $this->cache->remember($plain, 'r1.person.days', fn (): array => $this->metrics->series($plain, Dimension::Day));
    }

    /**
     * Días con capacidad y sin ninguna hora, hasta ayer y nunca antes de su alta (como la tarjeta
     * de Inicio): aunque su horario empiece antes y cuente en la capacidad, no se le reclaman días
     * en los que aún no tenía cuenta.
     *
     * @param  list<DayPoint>  $series
     * @return list<array{date: string, capacity_minutes: int, week: string}>
     */
    private function unloggedDays(User $user, array $series): array
    {
        $yesterday = LocalTime::today()->subDay()->toDateString();
        $joined = $user->created_at !== null ? LocalTime::dateOf($user->created_at) : null;
        $days = [];

        foreach ($series as $day) {
            if ($day['bucket'] <= $yesterday && ($joined === null || $day['bucket'] >= $joined)
                && $day['capacity_minutes'] > 0 && $day['logged_minutes'] === 0) {
                $days[] = [
                    'date' => $day['bucket'],
                    'capacity_minutes' => $day['capacity_minutes'],
                    'week' => Week::containing($day['bucket'])->iso(),
                ];
            }
        }

        return $days;
    }

    /**
     * Días sin imputar para exportar: fecha, día de la semana y jornada.
     *
     * @param  list<array{date: string, capacity_minutes: int, week: string}>  $days
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function unloggedTable(array $days): array
    {
        $headers = [__('reports.r1.columns.date'), __('reports.r1.columns.weekday'), __('reports.r1.columns.day_capacity')];

        $rows = array_map(function (array $day): array {
            $date = CarbonImmutable::parse($day['date']);

            return [$date->format('d/m/Y'), __('reports.r1.weekdays.'.$date->dayOfWeekIso), TableExporter::hours($day['capacity_minutes'])];
        }, $days);

        return [$headers, $rows];
    }

    /**
     * Detalle diario para exportar: fecha, día, capacidad, imputadas, facturables, ocupación del
     * día (vacía en los días que aún no han llegado: no es un 0 %) y, con datos económicos, el
     * ingreso estimado.
     *
     * @param  list<DayPoint>  $days
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function daysTable(array $days, bool $financials): array
    {
        $headers = [
            __('reports.r1.columns.date'),
            __('reports.r1.columns.weekday'),
            __('reports.r1.columns.capacity'),
            __('reports.r1.columns.logged'),
            __('reports.r1.columns.billable'),
            __('reports.r1.columns.occupancy'),
        ];

        if ($financials) {
            $headers[] = __('reports.r1.columns.income');
        }

        $today = LocalTime::todayString();
        $rows = [];
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day['bucket']);
            $line = [
                $date->format('d/m/Y'),
                __('reports.r1.weekdays.'.$date->dayOfWeekIso),
                TableExporter::hours($day['capacity_minutes']),
                TableExporter::hours($day['logged_minutes']),
                TableExporter::hours($day['billable_minutes']),
                $day['bucket'] > $today ? null : self::percent(Metrics::ratio($day['logged_minutes'], $day['capacity_minutes'])),
            ];

            if ($financials) {
                $line[] = TableExporter::money($day['income']);
            }

            $rows[] = $line;
        }

        return [$headers, $rows];
    }
}
