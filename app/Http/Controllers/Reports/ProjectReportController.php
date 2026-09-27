<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\EstimateComparison;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\TaskStatusCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informe de un proyecto (SPEC §10.3; R2): estimado frente a real (por tarea y por tipo, con la
 * regla de subtareas del SPEC §6), horas por persona, por tipo de tarea y por semana, estado de las
 * tareas (por estado y vencidas) e hitos, con KPIs e ingreso y rentabilidad si hay permiso.
 *
 * Quién (D-044, ProjectPolicy::viewReport = viewAllTime): admins, responsables y gestores del
 * proyecto. Las horas pasan por ReportScope: un responsable que no gestiona el proyecto ve las de su
 * equipo (D-021). Exporta con ?formato=xlsx|csv&tabla=tareas|estimado-por-tipo|personas|tipos|semanas.
 */
class ProjectReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope;

    public const array TABLES = ['tareas', 'estimado-por-tipo', 'personas', 'tipos', 'semanas'];

    public function __invoke(
        Request $request,
        Project $project,
        Metrics $metrics,
        EstimateComparison $estimates,
        ReportCache $cache,
        TableExporter $exporter,
    ): Response|StreamedResponse {
        $this->authorize('viewReport', $project);

        /** @var User $user */
        $user = $request->user();
        $scope = $this->reportScope($request, ['projectIds' => [$project->id], 'clientIds' => []]);
        // Quien gestiona el proyecto (o un admin) ve todas sus horas: la precisión de estimación
        // cuenta las tareas de cualquier responsable. Un responsable, las de su equipo (D-021).
        $everyAssignee = $user->isAdmin() || $user->isManagerOf($project);

        $data = $cache->remember($scope, 'r2.project.'.$project->id, fn (): array => [
            'summary' => $metrics->summary($scope, withCapacity: false, everyAssignee: $everyAssignee),
            'by_person' => $metrics->breakdown($scope, Dimension::Person),
            'by_type' => $metrics->breakdown($scope, Dimension::TaskType),
            'weekly' => $this->weekly($metrics->breakdown($scope, Dimension::Week), $scope->filters),
            'estimates' => $estimates->forProject($scope, $project),
            'tasks' => $this->taskStatus($project, $scope->filters),
            'milestones' => $this->milestones($project),
        ]);

        $format = $request->query('formato');
        if (is_string($format) && in_array($format, TableExporter::FORMATS, true)) {
            return $this->export($exporter, $project, $scope, $data, $request->query('tabla'), $format);
        }

        $comparison = null;
        if ($scope->filters->compare) {
            $previous = $scope->withFilters($scope->filters->comparison());
            $comparison = $cache->remember($previous, 'r2.project.'.$project->id.'.summary', fn (): array => $metrics->summary($previous, withCapacity: false, everyAssignee: $everyAssignee));
        }

        $project->loadMissing(['client' => fn ($query) => $query->withTrashed()->select(['id', 'name'])]);

        return Inertia::render('reports/project', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'billing_type' => $project->billing_type->value,
                'status' => $project->status->value,
                'budget_minutes' => $project->budget_minutes,
                'client' => $project->client === null ? null : ['id' => $project->client->id, 'name' => $project->client->name],
            ],
            'filters' => $this->filterProps(new ReportScope($user, ReportFilters::fromQuery($request->query())->with(['projectIds' => [], 'clientIds' => []]))),
            'scope' => ['team_only' => ! $user->isAdmin() && ! $user->isManagerOf($project)],
            'summary' => $data['summary'],
            'comparison' => $comparison,
            'byPerson' => $data['by_person'],
            'byType' => $data['by_type'],
            'weekly' => $data['weekly'],
            'estimates' => $data['estimates'],
            'tasks' => $data['tasks'],
            'milestones' => $data['milestones'],
        ]);
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
    private function export(TableExporter $exporter, Project $project, ReportScope $scope, array $data, mixed $table, string $format): StreamedResponse
    {
        $table = is_string($table) && in_array($table, self::TABLES, true) ? $table : 'tareas';
        $financials = $scope->canSeeFinancials();
        $name = self::text('reports.r2.project.export_name', ['project' => $project->code]).' '.$table;
        $c = fn (string $key): string => self::text('reports.r2.project.columns.'.$key);
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
                    $task['type']['name'] ?? self::text('reports.r2.no_type'),
                    $task['status']['name'],
                    $task['estimated_minutes'] === null ? null : TableExporter::hours($task['estimated_minutes']),
                    TableExporter::hours($task['actual_minutes']),
                    ...$deviation($task['estimated_minutes'], $task['actual_minutes']),
                ];
            }

            $totals = $data['estimates']['totals'];
            if ($totals['other_minutes'] > 0) {
                $rows[] = [self::text('reports.r2.project.other_tasks'), '', '', '', null, TableExporter::hours($totals['other_minutes']), null, null];
            }
            $rows[] = [self::text('reports.r2.total'), '', '', '', TableExporter::hours($totals['estimated_minutes']), TableExporter::hours($totals['actual_minutes']), null, null];

            return $exporter->download($name, [$c('task'), $c('parent'), $c('type'), $c('status'), $c('estimated'), $c('actual'), $c('deviation'), $c('deviation_pct')], $rows, $format);
        }

        if ($table === 'estimado-por-tipo') {
            $rows = array_map(fn (array $row): array => [
                $row['type']['name'] ?? self::text('reports.r2.no_type'),
                TableExporter::hours($row['estimated_minutes']),
                TableExporter::hours($row['actual_minutes']),
                ...$deviation($row['estimated_minutes'] > 0 ? $row['estimated_minutes'] : null, $row['actual_minutes']),
            ], $data['estimates']['by_type']);

            return $exporter->download($name, [$c('type'), $c('estimated'), $c('actual'), $c('deviation'), $c('deviation_pct')], $rows, $format);
        }

        if ($table === 'semanas') {
            $headers = [$c('week'), $c('logged'), $c('billable'), $c('in_bank'), $c('overage')];
            if ($financials) {
                $headers[] = $c('income');
            }
            $rows = array_map(fn (array $week): array => [
                $week['week'],
                TableExporter::hours($week['logged_minutes']),
                TableExporter::hours($week['billable_minutes']),
                TableExporter::hours($week['in_bank_minutes']),
                TableExporter::hours($week['overage_minutes']),
                ...($financials ? [TableExporter::money($week['income'] ?? '0.00')] : []),
            ], $data['weekly']);

            return $exporter->download($name, $headers, $rows, $format);
        }

        // personas o tipos (horas del periodo).
        $source = $table === 'personas' ? $data['by_person'] : $data['by_type'];
        $headers = [$c($table === 'personas' ? 'person' : 'type'), $c('logged'), $c('billable'), $c('in_bank'), $c('overage')];
        if ($financials) {
            array_push($headers, $c('income'), $c('cost'));
        }
        $rows = array_map(fn (array $row): array => [
            $row['name'],
            TableExporter::hours($row['logged_minutes']),
            TableExporter::hours($row['billable_minutes']),
            TableExporter::hours($row['in_bank_minutes']),
            TableExporter::hours($row['overage_minutes']),
            ...($financials ? [TableExporter::money($row['income']), TableExporter::money($row['cost'])] : []),
        ], $source);

        return $exporter->download($name, $headers, $rows, $format);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
