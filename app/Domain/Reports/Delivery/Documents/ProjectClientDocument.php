<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\Project\ProjectClientReport;
use App\Domain\Reports\ReportScope;
use App\Enums\HourBankStatus;
use App\Enums\PortalPersonDisplay;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Informe de un proyecto «Para el cliente» (?version=cliente, D-241): el PDF con el estilo de Audax
 * para enviárselo, y su Excel (un libro con una hoja por sección) o una tabla en CSV. Solo lo que
 * vería el cliente en el portal (ProjectClientReport): sus horas visibles (nunca borradores), las
 * personas como las ve él y las bolsas con las cifras del portal; nunca costes, tarifas, importes,
 * márgenes ni estimaciones.
 *
 * Quién (D-242): quien ve el informe del proyecto (viewReport) y además ve TODAS sus horas (un
 * admin o quien gestiona el proyecto). Un responsable que solo ve las de su equipo no puede
 * mandarle al cliente un informe al que le faltan horas.
 *
 * @phpstan-import-type ClientData from ProjectClientReport
 */
final class ProjectClientDocument extends BaseDocument
{
    use BuildsReportScope, ProjectFullPieces;

    public const array TABLES = ['resumen', 'tareas', 'personas', 'tipos', 'semanas', 'meses', 'bolsas', 'entradas'];

    /** Semanas como mucho para enseñar la evolución semanal en el PDF (si no, solo por meses). */
    private const int PDF_MAX_WEEKS = 14;

    public function __construct(private readonly ProjectClientReport $report) {}

    public function title(ReportRequest $request, User $as): string
    {
        [$project, $scope] = $this->authorized($request, $as);

        return $this->titleFor($project, $scope);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$project, $scope] = $this->authorized($request, $as);
        $data = $this->report->data($scope, $project);
        $title = $this->titleFor($project, $scope);
        $requested = self::queryString($request, 'tabla');

        if ($requested !== null && in_array($requested, self::TABLES, true)) {
            [$headers, $rows] = $this->sheet($requested, $project, $scope, $data);

            return new ExportTable(self::t('reports.r2.project.export_client', ['project' => $project->code]).' '.$requested, $headers, $rows, $title);
        }

        [$headers, $rows] = $this->sheet('tareas', $project, $scope, $data);
        $sheets = [];
        foreach (self::TABLES as $table) {
            if (($table === 'bolsas' && $data['banks'] === []) || ($table === 'personas' && self::hidesPeople($project))) {
                continue;
            }
            [$sheetHeaders, $sheetRows] = $table === 'tareas' ? [$headers, $rows] : $this->sheet($table, $project, $scope, $data);
            $sheets[] = new ExportSheet(self::t('reports.r2.project.sheets.'.$table), $sheetHeaders, $sheetRows);
        }

        return new ExportTable(self::t('reports.r2.project.export_client', ['project' => $project->code]), $headers, $rows, $title, $sheets);
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$project, $scope] = $this->authorized($request, $as);
        $data = $this->report->data($scope, $project);
        $client = $project->client_id === null ? null : $project->client()->withTrashed()->first(['id', 'name']);
        $weeks = count($data['weekly']) <= self::PDF_MAX_WEEKS;

        $facts = [
            [self::t('report_pdf.cover.period'), PdfFormat::text('report_pdf.period.range', [
                'from' => $scope->filters->from->format('d/m/Y'),
                'to' => $scope->filters->to->format('d/m/Y'),
            ])],
            [self::t('report_pdf.filters.cliente'), $client->name ?? self::t('report_pdf.internal_project')],
            [self::t('report_pdf.project.billing'), $project->billing_type->label()],
            ...($project->budget_minutes !== null ? [[self::t('report_pdf.project.budget'), PdfFormat::minutes($project->budget_minutes)]] : []),
            [self::t('report_pdf.project_client.hours_shown'), self::t('report_pdf.project_client.visibility.'.ProjectClientReport::visibility($project)->value)],
        ];

        return new ReportPdf(
            view: 'reports.pdf.project-client',
            title: $this->titleFor($project, $scope),
            filename: self::filename(self::t('report_pdf.files.project_client'), $project->code, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.project'), $project->code.' · '.$project->name, PdfFormat::period($scope->filters), $facts, $as),
                'kpis' => $this->kpisFor($project, $data),
                'visibility' => self::t('report_pdf.project_client.lead.'.ProjectClientReport::visibility($project)->value),
                'banks' => $data['banks'] === [] ? null : $this->banksTable($data['banks']),
                'tasks' => PdfTable::make(
                    [[self::t('report_pdf.columns.task')], [self::t('report_pdf.columns.hours'), true]],
                    array_map(fn (array $task): array => [($task['depth'] === 1 ? '↳ ' : '').$task['title'], PdfFormat::minutes($task['minutes'])], $data['tasks']),
                    $data['tasks'] === [] ? null : [self::t('report_pdf.total'), PdfFormat::minutes($data['period_minutes'])],
                    compact: count($data['tasks']) > 30,
                    empty: self::t('report_pdf.no_hours'),
                ),
                'people' => self::hidesPeople($project) ? null : $this->hoursTable(self::t('report_pdf.columns.person'), $data['by_person'], $data['period_minutes']),
                'types' => $this->hoursTable(self::t('report_pdf.columns.type'), $data['by_type'], $data['period_minutes']),
                'months' => PdfTable::make(
                    [[self::t('report_pdf.columns.month')], [self::t('report_pdf.columns.hours'), true]],
                    array_map(fn (array $month): array => [ucfirst(PdfFormat::month($month['month'])), PdfFormat::minutes($month['minutes'])], $data['monthly']),
                    $data['monthly'] === [] ? null : [self::t('report_pdf.total'), PdfFormat::minutes($data['period_minutes'])],
                ),
                'weeks' => ! $weeks ? null : PdfTable::make(
                    [[self::t('report_pdf.columns.week')], [self::t('report_pdf.columns.hours'), true]],
                    array_map(fn (array $week): array => [self::t('report_pdf.week_of', ['date' => PdfFormat::date($week['week'])]), PdfFormat::minutes($week['minutes'])], $data['weekly']),
                    $data['weekly'] === [] ? null : [self::t('report_pdf.total'), PdfFormat::minutes($data['period_minutes'])],
                ),
                'entries' => self::entriesPdf($this->report->entries($scope, $project, self::PDF_ENTRIES), false),
                'entries_more' => max($data['entry_count'] - self::PDF_ENTRIES, 0),
                'definitions' => array_map(fn (string $key): array => [
                    self::t('report_pdf.project_client.definitions.'.$key.'.label'),
                    self::t('report_pdf.project_client.definitions.'.$key.'.definition'),
                ], $data['banks'] === [] ? ['hours'] : ['hours', 'within', 'overage', 'remaining']),
            ],
            landscape: false,
        );
    }

    /**
     * @return array{0: Project, 1: ReportScope}
     *
     * @throws AuthorizationException
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $project = self::routeModel($request, 'project', Project::class);
        self::authorizeFor($as, 'viewReport', $project);

        if (! ProjectDocument::everyAssignee($as, $project)) {
            throw new AuthorizationException(self::t('report_pdf.project_client.forbidden'));
        }

        return [$project, $this->scopeFor($as, $request->query, ['projectIds' => [$project->id], 'clientIds' => []])];
    }

    private function titleFor(Project $project, ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.project_client'), $project->code, PdfFormat::period($scope->filters));
    }

    /**
     * Con «Equipo» (portal_person_display = team), el cliente no ve personas: sin reparto por persona.
     */
    private static function hidesPeople(Project $project): bool
    {
        return ProjectClientReport::display($project) === PortalPersonDisplay::Team;
    }

    /**
     * Cifras clave: horas del periodo, horas acumuladas del proyecto, el presupuesto y, con bolsas,
     * lo que queda en ellas (tal como lo ve el cliente).
     *
     * @param  ClientData  $data
     * @return list<array{label: string, value: string, detail: string|null}>
     */
    private function kpisFor(Project $project, array $data): array
    {
        $k = fn (string $key): string => self::t('report_pdf.project_client.kpis.'.$key);
        $kpis = [
            ['label' => $k('period'), 'value' => PdfFormat::minutes($data['period_minutes']), 'detail' => null],
            ['label' => $k('lifetime'), 'value' => PdfFormat::minutes($data['lifetime_minutes']), 'detail' => null],
        ];

        if ($project->budget_minutes !== null && $project->budget_minutes > 0) {
            $kpis[] = ['label' => $k('budget'), 'value' => PdfFormat::percent($data['lifetime_minutes'] / $project->budget_minutes, 0),
                'detail' => self::t('report_pdf.project_client.kpis.budget_detail', ['budget' => PdfFormat::minutes($project->budget_minutes)])];
        }

        $open = array_values(array_filter($data['banks'], fn (array $bank): bool => in_array($bank['status'], [HourBankStatus::Active->value, HourBankStatus::Exhausted->value], true)));
        if ($open !== []) {
            $kpis[] = ['label' => $k('remaining'), 'value' => PdfFormat::minutes(array_sum(array_column($open, 'remaining_minutes'))),
                'detail' => trans_choice('report_pdf.project_client.kpis.open_banks', count($open), ['count' => count($open)])];
        }

        return $kpis;
    }

    /**
     * @param  list<array{name: string, minutes: int}>  $rows
     * @return array<string, mixed>
     */
    private function hoursTable(string $label, array $rows, int $total): array
    {
        return PdfTable::make(
            [[$label], [self::t('report_pdf.columns.hours'), true], [self::t('report_pdf.columns.share'), true]],
            array_map(fn (array $row): array => [$row['name'], PdfFormat::minutes($row['minutes']), PdfFormat::percent($total > 0 ? $row['minutes'] / $total : null)], $rows),
            $rows === [] ? null : [self::t('report_pdf.total'), PdfFormat::minutes($total), ''],
            empty: self::t('report_pdf.no_hours'),
        );
    }

    /**
     * @param  ClientData['banks']  $banks
     * @return array<string, mixed>
     */
    private function banksTable(array $banks): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.bank')], [self::t('report_pdf.columns.status')], [self::t('report_pdf.columns.validity')],
                [self::t('report_pdf.columns.contracted'), true], [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true],
                [self::t('report_pdf.columns.remaining'), true], [self::t('report_pdf.columns.consumed_pct'), true], [self::t('report_pdf.columns.period_hours'), true]],
            array_map(fn (array $bank): array => [
                $bank['name'],
                HourBankStatus::from($bank['status'])->label(),
                PdfFormat::date($bank['start_date']).($bank['end_date'] !== null ? ' – '.PdfFormat::date($bank['end_date']) : ''),
                PdfFormat::minutes($bank['total_minutes']),
                PdfFormat::minutes($bank['within_minutes']),
                $bank['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($bank['overage_minutes']), 'overage') : PdfFormat::overage(0),
                PdfFormat::minutes($bank['remaining_minutes']),
                PdfFormat::percent($bank['percent']),
                PdfFormat::minutes($bank['period_minutes']),
            ], $banks),
        );
    }

    /**
     * @param  ClientData  $data
     * @return array{0: list<string>, 1: iterable<array<int, string|int|float|bool|null>>}
     */
    private function sheet(string $table, Project $project, ReportScope $scope, array $data): array
    {
        $c = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);
        $hours = fn (string $first, array $rows, int $total): array => [
            [$c($first), $c('hours'), $c('minutes')],
            [...array_map(fn (array $row): array => [$row['name'], TableExporter::hours($row['minutes']), $row['minutes']], $rows),
                [self::t('reports.r2.total'), TableExporter::hours($total), $total]],
        ];

        return match ($table) {
            'resumen' => [[$c('concept'), $c('value')], $this->summaryRows($project, $scope, $data)],
            'tareas' => [[$c('task'), $c('parent'), $c('hours'), $c('minutes')], [
                ...array_map(fn (array $task): array => [$task['title'], $task['parent'] ?? '', TableExporter::hours($task['minutes']), $task['minutes']], $data['tasks']),
                [self::t('reports.r2.total'), '', TableExporter::hours($data['period_minutes']), $data['period_minutes']],
            ]],
            'personas' => $hours('person', $data['by_person'], $data['period_minutes']),
            'tipos' => $hours('type', $data['by_type'], $data['period_minutes']),
            'semanas' => $hours('week', array_map(fn (array $week): array => ['name' => $week['week'], 'minutes' => $week['minutes']], $data['weekly']), $data['period_minutes']),
            'meses' => $hours('month', array_map(fn (array $month): array => ['name' => substr($month['month'], 0, 7), 'minutes' => $month['minutes']], $data['monthly']), $data['period_minutes']),
            'bolsas' => [
                [$c('bank'), $c('bank_status'), $c('start'), $c('end'), $c('contracted'), $c('in_bank'), $c('overage'), $c('remaining'), $c('consumed_pct'), $c('period_hours')],
                array_map(fn (array $bank): array => [
                    $bank['name'],
                    HourBankStatus::from($bank['status'])->label(),
                    $bank['start_date'],
                    $bank['end_date'],
                    TableExporter::hours($bank['total_minutes']),
                    TableExporter::hours($bank['within_minutes']),
                    TableExporter::hours($bank['overage_minutes']),
                    TableExporter::hours($bank['remaining_minutes']),
                    round($bank['percent'] * 100, 1),
                    TableExporter::hours($bank['period_minutes']),
                ], $data['banks']),
            ],
            default => [self::entryHeaders(false), self::entrySheetRows($this->report->entries($scope, $project, TableExporter::MAX_ROWS - 1), $data['entry_count'], TableExporter::MAX_ROWS - 1, false)],
        };
    }

    /**
     * Hoja «Resumen»: el proyecto, el periodo, qué horas lleva y las cifras clave.
     *
     * @param  ClientData  $data
     * @return list<array{0: string, 1: string|float|null}>
     */
    private function summaryRows(Project $project, ReportScope $scope, array $data): array
    {
        $o = fn (string $key): string => self::t('report_pdf.project.overview.'.$key);
        $client = $project->client_id === null ? null : $project->client()->withTrashed()->value('name');

        $rows = [
            [$o('project'), $project->code.' · '.$project->name],
            [$o('client'), $client ?? self::t('report_pdf.internal_project')],
            [$o('billing'), $project->billing_type->label()],
            [$o('period'), $scope->filters->from->toDateString().' – '.$scope->filters->to->toDateString()],
            [self::t('report_pdf.project_client.hours_shown'), self::t('report_pdf.project_client.visibility.'.ProjectClientReport::visibility($project)->value)],
            [self::t('report_pdf.project_client.kpis.period'), TableExporter::hours($data['period_minutes'])],
            [self::t('report_pdf.project_client.kpis.lifetime'), TableExporter::hours($data['lifetime_minutes'])],
        ];

        if ($project->budget_minutes !== null) {
            $rows[] = [$o('budget'), TableExporter::hours($project->budget_minutes)];
        }

        return $rows;
    }
}
