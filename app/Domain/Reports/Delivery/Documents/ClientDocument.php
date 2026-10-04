<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankHistory;
use App\Domain\Reports\BankUsage;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Money;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\HourBankStatus;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Informe de un cliente (/informes/clientes/{client}, SPEC §10.2; R2, D-044): el alcance (un
 * gestor, solo los proyectos del cliente que gestiona), los datos de la página (en caché), sus
 * tablas de exportación (?tabla=proyectos|meses|bolsas) y su PDF. Lo usan el controlador y el
 * generador (D-139).
 *
 * @phpstan-type ClientData array{summary: array{logged_minutes: int, billable_minutes: int, overage_minutes: int, income: string|null, cost: string|null, margin: string|null},
 *     banked: array{has_bank: bool, in_bank_minutes: int},
 *     projects: list<array{name: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null, has_bank: bool}>,
 *     timeline: array{bucket: string, buckets: list<string>, series: list<array{key: string, name: string, total: int}>, cells: array<string, array<string, int>>},
 *     banks: list<array<string, mixed>>, all_banks: list<array<string, mixed>>, history: list<list<array<string, mixed>>>}
 * @phpstan-type ClientContext array{client: Client, scope: ReportScope, url_filters: ReportFilters, limited: bool, project_ids: list<int>, every_assignee: bool}
 */
final class ClientDocument extends BaseDocument
{
    use BuildsReportScope, PdfPieces;

    public const array TABLES = ['proyectos', 'meses', 'bolsas'];

    /** Periodos de más días se agrupan por mes; los demás, por semana. */
    public const int MONTHLY_FROM_DAYS = 62;

    private const array KPIS = ['logged', 'billable', 'billability', 'in_bank', 'overage', 'estimation', 'income', 'cost', 'margin'];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly PivotReport $pivot,
        private readonly ReportCache $cache,
        private readonly HourBankHistory $history,
        private readonly HourBankCommitment $commitment,
        private readonly BankUsage $bankUsage,
    ) {}

    /**
     * Alcance del informe para $user: admins y responsables, todo el cliente; un gestor, solo los
     * proyectos del cliente que gestiona (se fuerza el filtro de proyecto).
     *
     * @param  array<string, mixed>  $query
     * @return ClientContext
     */
    public function context(User $user, Client $client, array $query): array
    {
        $urlFilters = ReportFilters::fromQuery($query)->with(['clientIds' => []]);

        $clientProjectIds = array_values(Project::query()->withTrashed()->where('client_id', $client->id)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all());
        $limited = ! $user->isAdmin() && ! $user->isDepartmentManager();
        $allowed = $limited ? array_values(array_intersect($clientProjectIds, $user->managedProjectIds())) : $clientProjectIds;

        $fixed = ['clientIds' => [$client->id]];
        if ($limited) {
            $ids = $urlFilters->projectIds === [] ? $allowed : array_values(array_intersect($urlFilters->projectIds, $allowed));
            // Sin ningún proyecto permitido en el filtro: nada (nunca «sin filtro»).
            $fixed['projectIds'] = $ids === [] ? [0] : $ids;
        }

        $scope = $this->scopeFor($user, $query, $fixed);

        return [
            'client' => $client,
            'scope' => $scope,
            'url_filters' => $urlFilters,
            'limited' => $limited,
            'project_ids' => $scope->filters->projectIds === [] ? $allowed : array_values(array_intersect($scope->filters->projectIds, $allowed)),
            // Un gestor ve todas las horas de sus proyectos (y un admin, todas): la precisión de
            // estimación cuenta las tareas de cualquier responsable. Un responsable, las de su equipo.
            'every_assignee' => $user->isAdmin() || $limited,
        ];
    }

    /**
     * Datos de la página y de la exportación (en caché, D-046).
     *
     * @param  ClientContext  $context
     * @return ClientData
     */
    public function data(array $context): array
    {
        $scope = $context['scope'];
        $bucket = $scope->filters->days() > self::MONTHLY_FROM_DAYS ? Dimension::Month : Dimension::Week;

        return $this->cache->remember($scope, 'r2.client.'.$context['client']->id, fn (): array => [
            'summary' => $this->metrics->summary($scope, withCapacity: false, everyAssignee: $context['every_assignee']),
            ...$this->projects($this->metrics->breakdown($scope, Dimension::Project), $this->bankUsage->byProject($scope)),
            'timeline' => $this->timeline($this->pivot->run($scope, Dimension::Project, $bucket), $scope->filters, $bucket),
            ...$this->banks($scope, $context['project_ids']),
        ]);
    }

    public function title(ReportRequest $request, User $as): string
    {
        return $this->titleFor($this->authorized($request, $as));
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $context = $this->authorized($request, $as);

        return $this->exportTable($context['client'], $context['scope'], $this->data($context), self::queryString($request, 'tabla'), $this->titleFor($context));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $context = $this->authorized($request, $as);
        $client = $context['client'];
        $scope = $context['scope'];
        $financials = $scope->canSeeFinancials();
        $data = $this->data($context);
        $summary = $data['summary'];
        $kpiSummary = ['in_bank_minutes' => $data['banked']['in_bank_minutes']] + $summary;

        return new ReportPdf(
            view: 'reports.pdf.client',
            title: $this->titleFor($context),
            filename: self::filename(self::t('report_pdf.files.client'), $client->name, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.client'), $client->name, PdfFormat::period($scope->filters),
                    self::filterFacts($context['url_filters'], ['cliente']), $as, $financials,
                    $context['limited'] ? self::t('report_pdf.client.limited') : (! $as->isAdmin() ? self::t('report_pdf.team_only') : null)),
                'kpis' => self::kpis($kpiSummary, $data['banked']['has_bank'] ? self::KPIS : array_values(array_diff(self::KPIS, ['in_bank'])), $financials),
                'projects' => $this->projectsPdf($data, $financials),
                'timeline' => $this->timelinePdf($data['timeline']),
                'timeline_monthly' => $data['timeline']['bucket'] === Dimension::Month->value,
                'banks' => $this->banksPdf($data['banks']),
                'definitions' => self::definitions(self::KPIS, $financials),
            ],
        );
    }

    /**
     * @return ClientContext
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $client = self::routeModel($request, 'client', Client::class);
        self::authorizeFor($as, 'viewReport', $client);

        return $this->context($as, $client, $request->query);
    }

    /**
     * @param  ClientContext  $context
     */
    private function titleFor(array $context): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.client'), $context['client']->name, PdfFormat::period($context['scope']->filters));
    }

    /**
     * @param  ClientData  $data
     * @return array<string, mixed>
     */
    private function projectsPdf(array $data, bool $financials): array
    {
        $columns = [[self::t('report_pdf.columns.project')], [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true],
            [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true]];
        if ($financials) {
            array_push($columns, [self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.cost'), true], [self::t('report_pdf.columns.margin'), true]);
        }

        $rows = [];
        foreach ($data['projects'] as $project) {
            $row = [$project['name'], PdfFormat::minutes($project['logged_minutes']), PdfFormat::minutes($project['billable_minutes']),
                $project['has_bank'] ? PdfFormat::minutes($project['in_bank_minutes']) : '—',
                $project['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($project['overage_minutes']), 'overage') : PdfFormat::overage(0)];
            if ($financials) {
                $income = (string) $project['income'];
                $cost = (string) $project['cost'];
                array_push($row, PdfFormat::money($income), PdfFormat::money($cost), PdfFormat::money(Money::round(Money::sub($income, $cost))));
            }
            $rows[] = $row;
        }

        $summary = $data['summary'];
        $sum = [self::t('report_pdf.total'), PdfFormat::minutes($summary['logged_minutes']), PdfFormat::minutes($summary['billable_minutes']),
            PdfFormat::minutes($data['banked']['in_bank_minutes']), PdfFormat::overage($summary['overage_minutes'])];
        if ($financials) {
            array_push($sum, PdfFormat::money($summary['income']), PdfFormat::money($summary['cost']), PdfFormat::money($summary['margin']));
        }

        return PdfTable::make($columns, $rows, $rows === [] ? null : $sum, empty: self::t('report_pdf.no_hours'));
    }

    /**
     * Horas de todos los proyectos por mes o por semana del periodo.
     *
     * @param  array{bucket: string, buckets: list<string>, series: list<array{key: string, name: string, total: int}>, cells: array<string, array<string, int>>}  $timeline
     * @return array<string, mixed>
     */
    private function timelinePdf(array $timeline): array
    {
        $monthly = $timeline['bucket'] === Dimension::Month->value;
        $rows = [];
        $all = 0;
        foreach ($timeline['buckets'] as $bucket) {
            $minutes = 0;
            foreach ($timeline['series'] as $serie) {
                $minutes += $timeline['cells'][$serie['key']][$bucket] ?? 0;
            }
            $all += $minutes;
            $rows[] = [
                $monthly ? ucfirst(PdfFormat::month($bucket)) : self::t('report_pdf.week_of', ['date' => PdfFormat::date($bucket)]),
                PdfFormat::minutes($minutes),
            ];
        }

        return PdfTable::make(
            [[self::t($monthly ? 'report_pdf.columns.month' : 'report_pdf.columns.week')], [self::t('report_pdf.columns.logged'), true]],
            $rows,
            $all > 0 ? [self::t('report_pdf.total'), PdfFormat::minutes($all)] : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $banks
     * @return array<string, mixed>
     */
    private function banksPdf(array $banks): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.bank')], [self::t('report_pdf.columns.status')], [self::t('report_pdf.columns.validity')],
                [self::t('report_pdf.columns.contracted'), true], [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true],
                [self::t('report_pdf.columns.remaining'), true], [self::t('report_pdf.columns.period_hours'), true]],
            array_map(fn (array $bank): array => [
                $bank['name'].' ('.$bank['project']['code'].')',
                HourBankStatus::from($bank['status'])->label(),
                PdfFormat::date($bank['start_date']).($bank['end_date'] !== null ? ' – '.PdfFormat::date($bank['end_date']) : ''),
                PdfFormat::minutes($bank['total_minutes']),
                PdfFormat::minutes($bank['in_bank_minutes']),
                $bank['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($bank['overage_minutes']), 'overage') : PdfFormat::overage(0),
                PdfFormat::minutes($bank['remaining_minutes']),
                PdfFormat::minutes($bank['period_in_bank_minutes'] + $bank['period_overage_minutes']),
            ], $banks),
            empty: self::t('report_pdf.client.no_banks'),
        );
    }

    /**
     * Resumen por proyecto con lo que va dentro de una bolsa de verdad (BankUsage): en los
     * proyectos sin horas en bolsas, «dentro de bolsa» no aplica (has_bank = false, 0 minutos).
     * banked: el total dentro de las bolsas (el in_bank_minutes de Metrics también cuenta las horas
     * sin bolsa) y si hay horas en alguna bolsa.
     *
     * @param  list<array{key: string|null, name: string, color: string|null, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null}>  $rows
     * @param  array<int, array{in_bank_minutes: int, overage_minutes: int}>  $usage
     * @return array{projects: list<array{key: string|null, name: string, color: string|null, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null, has_bank: bool}>, banked: array{has_bank: bool, in_bank_minutes: int}}
     */
    private function projects(array $rows, array $usage): array
    {
        $projects = array_map(function (array $row) use ($usage): array {
            $bank = $row['key'] === null ? null : ($usage[(int) $row['key']] ?? null);

            return [...$row, 'in_bank_minutes' => $bank['in_bank_minutes'] ?? 0, 'has_bank' => $bank !== null];
        }, $rows);

        return [
            'projects' => $projects,
            'banked' => [
                'has_bank' => $usage !== [],
                'in_bank_minutes' => array_sum(array_column($usage, 'in_bank_minutes')),
            ],
        ];
    }

    /**
     * Horas por proyecto y cubo de tiempo (mes o semana), con todos los cubos del periodo.
     *
     * @param  array{rows: list<array{key: string|null, name: string}>, cells: array<string, array<string, int>>, row_totals: array<string, int>}  $pivot
     * @return array{bucket: string, buckets: list<string>, series: list<array{key: string, name: string, total: int}>, cells: array<string, array<string, int>>}
     */
    private function timeline(array $pivot, ReportFilters $filters, Dimension $bucket): array
    {
        $buckets = [];
        $cursor = $bucket === Dimension::Month ? $filters->from->startOfMonth() : $filters->from->startOfWeek(CarbonImmutable::MONDAY);
        while ($cursor->lessThanOrEqualTo($filters->to)) {
            $buckets[] = $cursor->toDateString();
            $cursor = $bucket === Dimension::Month ? $cursor->addMonthNoOverflow() : $cursor->addWeek();
        }

        return [
            'bucket' => $bucket->value,
            'buckets' => $buckets,
            'series' => array_map(fn (array $row): array => [
                'key' => (string) $row['key'],
                'name' => $row['name'],
                'total' => $pivot['row_totals'][(string) $row['key']] ?? 0,
            ], $pivot['rows']),
            'cells' => $pivot['cells'],
        ];
    }

    /**
     * Bolsas de los proyectos del informe: las abiertas y las que tienen horas en el periodo (con
     * su consumo total, que ve cualquier interno, D-021, y lo del periodo, que pasa por el alcance),
     * todas para la exportación y las cadenas de renovaciones (SPEC §8.8).
     *
     * @param  list<int>  $projectIds
     * @return array{banks: list<array<string, mixed>>, all_banks: list<array<string, mixed>>, history: list<list<array<string, mixed>>>}
     */
    private function banks(ReportScope $scope, array $projectIds): array
    {
        if ($projectIds === []) {
            return ['banks' => [], 'all_banks' => [], 'history' => []];
        }

        $banks = HourBank::query()
            ->whereIn('project_id', $projectIds)
            ->when($scope->filters->bankIds !== [], fn ($query) => $query->whereIn('id', $scope->filters->bankIds))
            ->with(['project' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name'])])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get(['id', 'project_id', 'name', 'status', 'start_date', 'end_date', 'total_minutes', 'consumed_minutes', 'overage_minutes', 'renewed_from_id']);

        /** @var array<int, array{in_bank: int, overage: int}> $period lo del periodo, por bolsa */
        $period = [];
        foreach ($this->metrics->breakdown($scope, Dimension::HourBank) as $row) {
            if ($row['key'] !== null) {
                $period[(int) $row['key']] = ['in_bank' => $row['in_bank_minutes'], 'overage' => $row['overage_minutes']];
            }
        }

        $open = $banks->filter(fn (HourBank $bank): bool => $bank->acceptsTime());
        $committed = $this->commitment->forBanks($open->modelKeys());

        $rows = [];
        foreach ($banks as $bank) {
            $rows[$bank->id] = [
                'id' => $bank->id,
                'project' => ['id' => $bank->project->id, 'code' => $bank->project->code, 'name' => $bank->project->name],
                'name' => $bank->name,
                'status' => $bank->status->value,
                'start_date' => $bank->start_date->toDateString(),
                'end_date' => $bank->end_date?->toDateString(),
                'total_minutes' => $bank->total_minutes,
                'consumed_minutes' => $bank->consumed_minutes,
                'overage_minutes' => $bank->overage_minutes,
                'in_bank_minutes' => $bank->in_bank_minutes,
                'remaining_minutes' => $bank->remaining_minutes,
                'committed_minutes' => $committed[$bank->id]['committed_minutes'] ?? 0,
                'period_in_bank_minutes' => $period[$bank->id]['in_bank'] ?? 0,
                'period_overage_minutes' => $period[$bank->id]['overage'] ?? 0,
            ];
        }

        $chains = array_map(
            fn (array $chain): array => array_map(fn (array $link): array => $rows[$link['id']], $chain),
            $this->history->chains($banks),
        );

        return [
            'banks' => array_values(array_filter($rows, fn (array $row): bool => in_array($row['status'], ['active', 'exhausted'], true)
                || $row['period_in_bank_minutes'] + $row['period_overage_minutes'] > 0)),
            'all_banks' => array_values($rows),
            'history' => $chains,
        ];
    }

    /**
     * @param  array{summary: array{logged_minutes: int, billable_minutes: int, overage_minutes: int, income: string|null, cost: string|null, margin: string|null},
     *     banked: array{has_bank: bool, in_bank_minutes: int},
     *     projects: list<array{name: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null, has_bank: bool}>, timeline: array{bucket: string, buckets: list<string>, series: list<array{key: string, name: string, total: int}>, cells: array<string, array<string, int>>}, all_banks: list<array<string, mixed>>}  $data
     */
    private function exportTable(Client $client, ReportScope $scope, array $data, mixed $table, string $title): ExportTable
    {
        $table = is_string($table) && in_array($table, self::TABLES, true) ? $table : 'proyectos';
        $financials = $scope->canSeeFinancials();
        $name = self::t('reports.r2.client.export_name', ['client' => $client->name]).' '.$table;
        $c = fn (string $key): string => self::t('reports.r2.client.columns.'.$key);

        if ($table === 'meses') {
            $headers = [$c('project'), $c($data['timeline']['bucket'] === Dimension::Month->value ? 'bucket_month' : 'bucket_week'), $c('logged')];
            $rows = [];
            foreach ($data['timeline']['series'] as $serie) {
                foreach ($data['timeline']['buckets'] as $bucket) {
                    $minutes = $data['timeline']['cells'][$serie['key']][$bucket] ?? 0;
                    if ($minutes > 0) {
                        $rows[] = [$serie['name'], $bucket, TableExporter::hours($minutes)];
                    }
                }
            }

            return new ExportTable($name, $headers, $rows, $title);
        }

        if ($table === 'bolsas') {
            $headers = [$c('bank'), $c('project'), $c('status'), $c('start'), $c('end'), $c('total'), $c('consumed'),
                $c('bank_in'), $c('bank_overage'), $c('remaining'), $c('period_in'), $c('period_overage')];
            $rows = array_map(fn (array $bank): array => [
                $bank['name'],
                $bank['project']['code'].' · '.$bank['project']['name'],
                HourBankStatus::from($bank['status'])->label(),
                $bank['start_date'],
                $bank['end_date'],
                TableExporter::hours($bank['total_minutes']),
                TableExporter::hours($bank['consumed_minutes']),
                TableExporter::hours($bank['in_bank_minutes']),
                TableExporter::hours($bank['overage_minutes']),
                TableExporter::hours($bank['remaining_minutes']),
                TableExporter::hours($bank['period_in_bank_minutes']),
                TableExporter::hours($bank['period_overage_minutes']),
            ], $data['all_banks']);

            return new ExportTable($name, $headers, $rows, $title);
        }

        $headers = [$c('project'), $c('logged'), $c('billable'), $c('in_bank'), $c('overage')];
        if ($financials) {
            array_push($headers, $c('income'), $c('cost'), $c('margin'));
        }
        // D-081: al final, los minutos (enteros) de las columnas de horas, que suman exacto su total.
        array_push($headers, $c('logged_minutes'), $c('billable_minutes'), $c('in_bank_minutes'), $c('overage_minutes'));

        // «Dentro de bolsa» solo en los proyectos con horas en bolsas (en los demás, vacío).
        $rows = [];
        foreach ($data['projects'] as $project) {
            $row = [$project['name'], TableExporter::hours($project['logged_minutes']), TableExporter::hours($project['billable_minutes']),
                $project['has_bank'] ? TableExporter::hours($project['in_bank_minutes']) : null, TableExporter::hours($project['overage_minutes'])];
            if ($financials) {
                $income = (string) $project['income'];
                $cost = (string) $project['cost'];
                array_push($row, TableExporter::money($income), TableExporter::money($cost), TableExporter::money(Money::round(Money::sub($income, $cost))));
            }
            array_push($row, $project['logged_minutes'], $project['billable_minutes'],
                $project['has_bank'] ? $project['in_bank_minutes'] : null, $project['overage_minutes']);
            $rows[] = $row;
        }

        // Los totales, los del resumen del servidor (INT-04): los importes de los proyectos son su
        // parte en céntimos del mismo total (RevenueCalculator), así que suman exactamente esto.
        $summary = $data['summary'];
        $total = [self::t('reports.r2.total'), TableExporter::hours($summary['logged_minutes']), TableExporter::hours($summary['billable_minutes']),
            TableExporter::hours($data['banked']['in_bank_minutes']), TableExporter::hours($summary['overage_minutes'])];
        if ($financials) {
            array_push($total, TableExporter::money($summary['income']), TableExporter::money($summary['cost']), TableExporter::money($summary['margin']));
        }
        array_push($total, $summary['logged_minutes'], $summary['billable_minutes'], $data['banked']['in_bank_minutes'], $summary['overage_minutes']);
        $rows[] = $total;

        return new ExportTable($name, $headers, $rows, $title);
    }
}
