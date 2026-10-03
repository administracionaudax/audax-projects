<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\EstimateComparison;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\BillingType;
use App\Enums\TaskStatusCategory;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Informe de un proyecto (/informes/proyectos/{project}, SPEC §10.3; R2, D-044): el alcance, los
 * datos de la página (en caché), sus tablas de exportación (?tabla=tareas|estimado-por-tipo|
 * personas|tipos|semanas) y su PDF. Lo usan el controlador y el generador (D-139).
 *
 * @phpstan-type ProjectData array{summary: array<string, mixed>, by_person: list<array<string, mixed>>, by_type: list<array<string, mixed>>,
 *     weekly: list<array{week: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null}>,
 *     estimates: array{tasks: list<array<string, mixed>>, by_type: list<array<string, mixed>>, totals: array<string, int>},
 *     tasks: array{by_status: list<array{id: int, name: string, color: string, category: string, count: int}>, by_category: array<string, int>, overdue: int, total: int},
 *     milestones: list<array{id: int, title: string, due_date: string|null, completed: bool, overdue: bool}>}
 */
final class ProjectDocument extends BaseDocument
{
    use BuildsReportScope, PdfPieces;

    public const array TABLES = ['tareas', 'estimado-por-tipo', 'personas', 'tipos', 'semanas'];

    /** Tareas del estimado frente a real en el PDF (las de más horas; la tabla entera, en Excel). */
    public const int PDF_TASKS = 60;

    private const array KPIS = ['logged', 'billable', 'billability', 'in_bank', 'overage', 'estimation', 'income', 'cost', 'margin'];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly EstimateComparison $estimates,
        private readonly ReportCache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function scope(User $user, Project $project, array $query): ReportScope
    {
        return $this->scopeFor($user, $query, ['projectIds' => [$project->id], 'clientIds' => []]);
    }

    /**
     * Quien gestiona el proyecto (o un admin) ve todas sus horas: la precisión de estimación
     * cuenta las tareas de cualquier responsable. Un responsable, las de su equipo (D-021).
     */
    public static function everyAssignee(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->isManagerOf($project);
    }

    /**
     * Datos de la página y de la exportación (en caché, D-046).
     *
     * @return ProjectData
     */
    public function data(ReportScope $scope, Project $project): array
    {
        $everyAssignee = self::everyAssignee($scope->viewer, $project);

        return $this->cache->remember($scope, 'r2.project.'.$project->id, fn (): array => [
            'summary' => $this->metrics->summary($scope, withCapacity: false, everyAssignee: $everyAssignee),
            'by_person' => $this->metrics->breakdown($scope, Dimension::Person),
            'by_type' => $this->metrics->breakdown($scope, Dimension::TaskType),
            'weekly' => $this->weekly($this->metrics->breakdown($scope, Dimension::Week), $scope->filters),
            'estimates' => $this->estimates->forProject($scope, $project),
            'tasks' => $this->taskStatus($project, $scope->filters),
            'milestones' => $this->milestones($project),
        ]);
    }

    public function title(ReportRequest $request, User $as): string
    {
        [$project, $scope] = $this->authorized($request, $as);

        return $this->titleFor($project, $scope);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$project, $scope] = $this->authorized($request, $as);

        return $this->exportTable($project, $scope, $this->data($scope, $project), self::queryString($request, 'tabla'), $this->titleFor($project, $scope));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$project, $scope] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $data = $this->data($scope, $project);
        $banks = $project->billing_type === BillingType::HourBank;
        $project->loadMissing(['client' => fn ($query) => $query->withTrashed()->select(['id', 'name'])]);
        $teamOnly = ! self::everyAssignee($as, $project);

        $facts = self::filterFacts($scope->filters, ['proyecto', 'cliente']);
        array_splice($facts, 1, 0, [
            [self::t('report_pdf.filters.cliente'), $project->client->name ?? self::t('report_pdf.internal_project')],
            [self::t('report_pdf.project.billing'), $project->billing_type->label()],
            ...($project->budget_minutes !== null ? [[self::t('report_pdf.project.budget'), PdfFormat::minutes($project->budget_minutes)]] : []),
        ]);

        return new ReportPdf(
            view: 'reports.pdf.project',
            title: $this->titleFor($project, $scope),
            filename: self::filename(self::t('report_pdf.files.project'), $project->code, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.project'), $project->code.' · '.$project->name, PdfFormat::period($scope->filters),
                    $facts, $as, $financials, $teamOnly ? self::t('report_pdf.team_only') : null),
                'kpis' => self::kpis($data['summary'], $banks ? self::KPIS : array_values(array_diff(self::KPIS, ['in_bank'])), $financials),
                'estimates_by_type' => $this->estimatesByTypePdf($data['estimates']['by_type']),
                'estimates' => $this->estimatesPdf($data['estimates']),
                'estimates_more' => max(count($data['estimates']['tasks']) - self::PDF_TASKS, 0),
                'people' => $this->hoursPdf(self::t('report_pdf.columns.person'), $data['by_person'], $data['summary'], $banks, $financials),
                'types' => $this->hoursPdf(self::t('report_pdf.columns.type'), $data['by_type'], $data['summary'], $banks, $financials),
                'weeks' => $this->weeksPdf($data['weekly'], $banks, $financials),
                'status' => $this->statusPdf($data['tasks']),
                'overdue_tasks' => $data['tasks']['overdue'],
                'milestones' => PdfTable::make(
                    [[self::t('report_pdf.columns.milestone')], [self::t('report_pdf.columns.due_date'), true], [self::t('report_pdf.columns.status')]],
                    array_map(fn (array $milestone): array => [
                        $milestone['title'],
                        PdfFormat::date($milestone['due_date']),
                        $milestone['completed'] ? self::t('report_pdf.project.milestone_done')
                            : ($milestone['overdue'] ? PdfTable::cell(self::t('report_pdf.project.milestone_overdue'), 'overage') : self::t('report_pdf.project.milestone_pending')),
                    ], $data['milestones']),
                    empty: self::t('report_pdf.project.no_milestones'),
                ),
                'definitions' => self::definitions(['logged', 'billable', 'in_bank', 'overage', 'estimation', 'income', 'cost', 'margin'], $financials),
            ],
            landscape: false,
        );
    }

    /**
     * @return array{0: Project, 1: ReportScope}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $project = self::routeModel($request, 'project', Project::class);
        self::authorizeFor($as, 'viewReport', $project);

        return [$project, $this->scope($as, $project, $request->query)];
    }

    private function titleFor(Project $project, ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.project'), $project->code, PdfFormat::period($scope->filters));
    }

    /**
     * Desviación legible: «+1:30 (+25,0 %)», o «—» sin estimación.
     */
    private static function deviation(?int $estimated, int $actual): string
    {
        if ($estimated === null) {
            return '—';
        }

        $difference = $actual - $estimated;
        $text = ($difference > 0 ? '+' : '').PdfFormat::minutes($difference);

        return $estimated > 0 ? $text.' ('.($difference > 0 ? '+' : '').PdfFormat::percent($difference / $estimated).')' : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function estimatesByTypePdf(array $rows): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.type')], [self::t('report_pdf.columns.estimated'), true], [self::t('report_pdf.columns.actual'), true], [self::t('report_pdf.columns.deviation'), true]],
            array_map(fn (array $row): array => [
                $row['type']['name'] ?? self::t('report_pdf.no_type'),
                PdfFormat::minutes((int) $row['estimated_minutes']),
                PdfFormat::minutes((int) $row['actual_minutes']),
                self::deviation((int) $row['estimated_minutes'] > 0 ? (int) $row['estimated_minutes'] : null, (int) $row['actual_minutes']),
            ], $rows),
            empty: self::t('report_pdf.project.no_estimates'),
        );
    }

    /**
     * Las tareas con más horas (estimadas o reales), con su desviación y el total.
     *
     * @param  array{tasks: list<array<string, mixed>>, totals: array<string, int>}  $estimates
     * @return array<string, mixed>
     */
    private function estimatesPdf(array $estimates): array
    {
        $tasks = $estimates['tasks'];
        usort($tasks, fn (array $a, array $b): int => max((int) $b['actual_minutes'], (int) ($b['estimated_minutes'] ?? 0)) <=> max((int) $a['actual_minutes'], (int) ($a['estimated_minutes'] ?? 0)));
        $tasks = array_slice($tasks, 0, self::PDF_TASKS);
        $totals = $estimates['totals'];

        return PdfTable::make(
            [[self::t('report_pdf.columns.task')], [self::t('report_pdf.columns.status')], [self::t('report_pdf.columns.estimated'), true],
                [self::t('report_pdf.columns.actual'), true], [self::t('report_pdf.columns.deviation'), true]],
            array_map(fn (array $task): array => [
                ($task['parent_id'] !== null ? '↳ ' : '').$task['title'],
                $task['status']['name'],
                $task['estimated_minutes'] === null ? '—' : PdfFormat::minutes((int) $task['estimated_minutes']),
                PdfFormat::minutes((int) $task['actual_minutes']),
                self::deviation($task['estimated_minutes'] === null ? null : (int) $task['estimated_minutes'], (int) $task['actual_minutes']),
            ], $tasks),
            $tasks === [] ? null : [self::t('report_pdf.total'), '', PdfFormat::minutes($totals['estimated_minutes']), PdfFormat::minutes($totals['actual_minutes'] + $totals['other_minutes']), ''],
            compact: true,
            empty: self::t('report_pdf.project.no_tasks'),
        );
    }

    /**
     * Horas por persona o por tipo, con su total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function hoursPdf(string $label, array $rows, array $summary, bool $banks, bool $financials): array
    {
        $columns = [[$label], [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true],
            ...($banks ? [[self::t('report_pdf.columns.in_bank'), true]] : []), [self::t('report_pdf.columns.overage'), true],
            ...($financials ? [[self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.cost'), true]] : [])];
        $line = fn (string $name, array $row): array => [
            $name,
            PdfFormat::minutes((int) $row['logged_minutes']),
            PdfFormat::minutes((int) $row['billable_minutes']),
            ...($banks ? [PdfFormat::minutes((int) $row['in_bank_minutes'])] : []),
            (int) $row['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage((int) $row['overage_minutes']), 'overage') : PdfFormat::overage(0),
            ...($financials ? [PdfFormat::money($row['income'] ?? null), PdfFormat::money($row['cost'] ?? null)] : []),
        ];

        return PdfTable::make($columns, array_map(fn (array $row): array => $line((string) $row['name'], $row), $rows),
            $rows === [] ? null : $line(self::t('report_pdf.total'), $summary), empty: self::t('report_pdf.no_hours'));
    }

    /**
     * @param  list<array{week: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null}>  $weeks
     * @return array<string, mixed>
     */
    private function weeksPdf(array $weeks, bool $banks, bool $financials): array
    {
        $columns = [[self::t('report_pdf.columns.week')], [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true],
            ...($banks ? [[self::t('report_pdf.columns.in_bank'), true]] : []), [self::t('report_pdf.columns.overage'), true],
            ...($financials ? [[self::t('report_pdf.columns.income'), true]] : [])];

        return PdfTable::make($columns, array_map(fn (array $week): array => [
            self::t('report_pdf.week_of', ['date' => PdfFormat::date($week['week'])]),
            PdfFormat::minutes($week['logged_minutes']),
            PdfFormat::minutes($week['billable_minutes']),
            ...($banks ? [PdfFormat::minutes($week['in_bank_minutes'])] : []),
            $week['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($week['overage_minutes']), 'overage') : PdfFormat::overage(0),
            ...($financials ? [PdfFormat::money($week['income'] ?? '0.00')] : []),
        ], $weeks), compact: count($weeks) > 20);
    }

    /**
     * @param  array{by_status: list<array{name: string, count: int}>, total: int, overdue: int}  $tasks
     * @return array<string, mixed>
     */
    private function statusPdf(array $tasks): array
    {
        $rows = array_values(array_filter(array_map(fn (array $status): ?array => $status['count'] > 0 ? [$status['name'], (string) $status['count']] : null, $tasks['by_status'])));

        return PdfTable::make([[self::t('report_pdf.columns.status')], [self::t('report_pdf.columns.tasks'), true]], $rows,
            $rows === [] ? null : [self::t('report_pdf.total'), (string) $tasks['total']], empty: self::t('report_pdf.project.no_tasks'));
    }

    /**
     * Semanas (lunes) del periodo sin huecos, con lo imputado, facturable, dentro de bolsa y exceso.
     *
     * @param  list<array{key: string|null, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null}>  $rows
     * @return list<array{week: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null}>
     */
    private function weekly(array $rows, ReportFilters $filters): array
    {
        $byWeek = [];
        foreach ($rows as $row) {
            $byWeek[substr((string) $row['key'], 0, 10)] = $row;
        }

        $weeks = [];
        for ($monday = $filters->from->startOfWeek(CarbonImmutable::MONDAY); $monday->lessThanOrEqualTo($filters->to); $monday = $monday->addWeek()) {
            $key = $monday->toDateString();
            $row = $byWeek[$key] ?? null;
            $weeks[] = [
                'week' => $key,
                'logged_minutes' => $row['logged_minutes'] ?? 0,
                'billable_minutes' => $row['billable_minutes'] ?? 0,
                'in_bank_minutes' => $row['in_bank_minutes'] ?? 0,
                'overage_minutes' => $row['overage_minutes'] ?? 0,
                'income' => $row === null ? null : $row['income'],
            ];
        }

        return $weeks;
    }

    /**
     * Estado actual de las tareas (sin hitos): cuántas hay en cada estado y categoría, y cuántas
     * abiertas están vencidas. Con los filtros de bolsa y tipo. Dos consultas.
     *
     * @return array{by_status: list<array{id: int, name: string, color: string, category: string, count: int}>,
     *     by_category: array<string, int>, overdue: int, total: int}
     */
    private function taskStatus(Project $project, ReportFilters $filters): array
    {
        $rows = Task::query()
            ->where('tasks.project_id', $project->id)
            ->where('tasks.is_milestone', false)
            ->when($filters->bankIds !== [], fn (Builder $query) => $query->whereIn('tasks.hour_bank_id', $filters->bankIds))
            ->when($filters->taskTypeIds !== [], fn (Builder $query) => $query->whereIn('tasks.task_type_id', $filters->taskTypeIds))
            ->toBase()
            ->selectRaw('tasks.status_id as status_id, COUNT(*) as total,
                SUM(CASE WHEN tasks.completed_at IS NULL AND tasks.due_date < ? THEN 1 ELSE 0 END) as overdue', [LocalTime::todayString()])
            ->groupBy('tasks.status_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->status_id);

        $byCategory = array_fill_keys(TaskStatusCategory::values(), 0);
        $byStatus = [];
        $overdue = 0;

        foreach (TaskStatus::query()->orderBy('position')->get(['id', 'name', 'color', 'category', 'position']) as $status) {
            $row = $rows->get($status->id);
            $count = (int) ($row->total ?? 0);
            $overdue += (int) ($row->overdue ?? 0);
            $byCategory[$status->category->value] += $count;
            $byStatus[] = ['id' => $status->id, 'name' => $status->name, 'color' => $status->color, 'category' => $status->category->value, 'count' => $count];
        }

        return [
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'overdue' => $overdue,
            'total' => array_sum($byCategory),
        ];
    }

    /**
     * Hitos del proyecto (tareas con is_milestone), por fecha; los que no tienen fecha, al final.
     *
     * @return list<array{id: int, title: string, due_date: string|null, completed: bool, overdue: bool}>
     */
    private function milestones(Project $project): array
    {
        $today = LocalTime::todayString();

        return array_values(Task::query()
            ->where('project_id', $project->id)
            ->where('is_milestone', true)
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'title', 'due_date', 'completed_at'])
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'completed' => $task->completed_at !== null,
                'overdue' => $task->completed_at === null && $task->due_date !== null && $task->due_date->toDateString() < $today,
            ])
            ->all());
    }

    /**
     * @param  array{by_person: list<array<string, mixed>>, by_type: list<array<string, mixed>>, weekly: list<array<string, mixed>>,
     *     estimates: array{tasks: list<array<string, mixed>>, by_type: list<array<string, mixed>>, totals: array<string, int>}}  $data
     */
    private function exportTable(Project $project, ReportScope $scope, array $data, mixed $table, string $title): ExportTable
    {
        $table = is_string($table) && in_array($table, self::TABLES, true) ? $table : 'tareas';
        $financials = $scope->canSeeFinancials();
        // «Dentro de bolsa» solo en proyectos de bolsas, como la página: en los demás sería lo imputado.
        $banks = $project->billing_type === BillingType::HourBank;
        $name = self::t('reports.r2.project.export_name', ['project' => $project->code]).' '.$table;
        $c = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);
        $deviation = fn (?int $estimated, int $actual): array => $estimated === null
            ? [null, null]
            : [TableExporter::hours($actual - $estimated), $estimated > 0 ? round(($actual - $estimated) / $estimated * 100, 1) : null];

        if ($table === 'tareas') {
            $titles = [];
            foreach ($data['estimates']['tasks'] as $task) {
                $titles[$task['id']] = $task['title'];
            }

            $rows = [];
            foreach ($data['estimates']['tasks'] as $task) {
                $rows[] = [
                    $task['title'],
                    $task['parent_id'] !== null ? ($titles[$task['parent_id']] ?? '') : '',
                    $task['type']['name'] ?? self::t('reports.r2.no_type'),
                    $task['status']['name'],
                    $task['estimated_minutes'] === null ? null : TableExporter::hours($task['estimated_minutes']),
                    TableExporter::hours($task['actual_minutes']),
                    ...$deviation($task['estimated_minutes'], $task['actual_minutes']),
                    // D-081: los minutos (enteros), que suman exacto su total.
                    $task['estimated_minutes'],
                    $task['actual_minutes'],
                ];
            }

            $totals = $data['estimates']['totals'];
            if ($totals['other_minutes'] > 0) {
                $rows[] = [self::t('reports.r2.project.other_tasks'), '', '', '', null, TableExporter::hours($totals['other_minutes']), null, null, null, $totals['other_minutes']];
            }
            $rows[] = [self::t('reports.r2.total'), '', '', '', TableExporter::hours($totals['estimated_minutes']), TableExporter::hours($totals['actual_minutes']), null, null,
                $totals['estimated_minutes'], $totals['actual_minutes']];

            return new ExportTable($name, [$c('task'), $c('parent'), $c('type'), $c('status'), $c('estimated'), $c('actual'), $c('deviation'), $c('deviation_pct'),
                $c('estimated_minutes'), $c('actual_minutes')], $rows, $title);
        }

        if ($table === 'estimado-por-tipo') {
            $rows = array_map(fn (array $row): array => [
                $row['type']['name'] ?? self::t('reports.r2.no_type'),
                TableExporter::hours($row['estimated_minutes']),
                TableExporter::hours($row['actual_minutes']),
                ...$deviation($row['estimated_minutes'] > 0 ? $row['estimated_minutes'] : null, $row['actual_minutes']),
            ], $data['estimates']['by_type']);

            return new ExportTable($name, [$c('type'), $c('estimated'), $c('actual'), $c('deviation'), $c('deviation_pct')], $rows, $title);
        }

        if ($table === 'semanas') {
            $headers = [$c('week'), $c('logged'), $c('billable'), ...($banks ? [$c('in_bank')] : []), $c('overage')];
            if ($financials) {
                $headers[] = $c('income');
            }
            $rows = array_map(fn (array $week): array => [
                $week['week'],
                TableExporter::hours($week['logged_minutes']),
                TableExporter::hours($week['billable_minutes']),
                ...($banks ? [TableExporter::hours($week['in_bank_minutes'])] : []),
                TableExporter::hours($week['overage_minutes']),
                ...($financials ? [TableExporter::money($week['income'] ?? '0.00')] : []),
            ], $data['weekly']);

            return new ExportTable($name, $headers, $rows, $title);
        }

        // personas o tipos (horas del periodo).
        $source = $table === 'personas' ? $data['by_person'] : $data['by_type'];
        $headers = [$c($table === 'personas' ? 'person' : 'type'), $c('logged'), $c('billable'), ...($banks ? [$c('in_bank')] : []), $c('overage')];
        if ($financials) {
            array_push($headers, $c('income'), $c('cost'));
        }
        $rows = array_map(fn (array $row): array => [
            $row['name'],
            TableExporter::hours($row['logged_minutes']),
            TableExporter::hours($row['billable_minutes']),
            ...($banks ? [TableExporter::hours($row['in_bank_minutes'])] : []),
            TableExporter::hours($row['overage_minutes']),
            ...($financials ? [TableExporter::money($row['income']), TableExporter::money($row['cost'])] : []),
        ], $source);

        return new ExportTable($name, $headers, $rows, $title);
    }
}
