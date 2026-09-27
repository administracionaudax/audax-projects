<?php

namespace App\Http\Controllers\Reports;

use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankHistory;
use App\Domain\Reports\BankUsage;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Money;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Enums\HourBankStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informe de un cliente (SPEC §10.2; R2): horas por proyecto y por mes (o por semana si el periodo
 * es corto), resumen por proyecto con rentabilidad, bolsas con su consumo (dentro y exceso por
 * separado) e histórico de renovaciones.
 *
 * Quién (D-044, ClientPolicy::viewReport): admins y responsables, todo el cliente; un gestor, solo
 * los proyectos del cliente que gestiona (se fuerza el filtro de proyecto). Las horas, además,
 * pasan por ReportScope (un responsable ve las de su equipo y las de los proyectos que gestiona).
 * Los importes, solo con view-financials. Exporta con ?formato=xlsx|csv&tabla=proyectos|meses|bolsas.
 */
class ClientReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope;

    public const array TABLES = ['proyectos', 'meses', 'bolsas'];

    /** Periodos de más días se agrupan por mes; los demás, por semana. */
    public const int MONTHLY_FROM_DAYS = 62;

    public function __invoke(
        Request $request,
        Client $client,
        Metrics $metrics,
        PivotReport $pivot,
        ReportCache $cache,
        HourBankHistory $history,
        HourBankCommitment $commitment,
        BankUsage $bankUsage,
        TableExporter $exporter,
    ): Response|StreamedResponse {
        $this->authorize('viewReport', $client);

        /** @var User $user */
        $user = $request->user();
        $urlFilters = ReportFilters::fromQuery($request->query())->with(['clientIds' => []]);

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

        $scope = $this->reportScope($request, $fixed);
        $projectIds = $scope->filters->projectIds === [] ? $allowed : array_values(array_intersect($scope->filters->projectIds, $allowed));
        $bucket = $scope->filters->days() > self::MONTHLY_FROM_DAYS ? Dimension::Month : Dimension::Week;
        // Un gestor ve todas las horas de sus proyectos (y un admin, todas): la precisión de
        // estimación cuenta las tareas de cualquier responsable. Un responsable, las de su equipo.
        $everyAssignee = $user->isAdmin() || $limited;

        $data = $cache->remember($scope, 'r2.client.'.$client->id, fn (): array => [
            'summary' => $metrics->summary($scope, withCapacity: false, everyAssignee: $everyAssignee),
            ...$this->projects($metrics->breakdown($scope, Dimension::Project), $bankUsage->byProject($scope)),
            'timeline' => $this->timeline($pivot->run($scope, Dimension::Project, $bucket), $scope->filters, $bucket),
            ...$this->banks($scope, $projectIds, $metrics, $history, $commitment),
        ]);

        $format = $request->query('formato');
        if (is_string($format) && in_array($format, TableExporter::FORMATS, true)) {
            return $this->export($exporter, $client, $scope, $data, $request->query('tabla'), $format);
        }

        $comparison = null;
        if ($scope->filters->compare) {
            $previous = $scope->withFilters($scope->filters->comparison());
            $comparison = $cache->remember($previous, 'r2.client.'.$client->id.'.summary', fn (): array => $metrics->summary($previous, withCapacity: false, everyAssignee: $everyAssignee));
        }

        return Inertia::render('reports/client', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active],
            'filters' => $this->filterProps(new ReportScope($user, $urlFilters)),
            'scope' => [
                'projects_only' => $limited,
                'team_only' => ! $user->isAdmin(),
            ],
            'summary' => $data['summary'],
            'comparison' => $comparison,
            'banked' => $data['banked'],
            'projects' => $data['projects'],
            'timeline' => $data['timeline'],
            'banks' => $data['banks'],
            'history' => $data['history'],
        ]);
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
    private function banks(ReportScope $scope, array $projectIds, Metrics $metrics, HourBankHistory $history, HourBankCommitment $commitment): array
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
        foreach ($metrics->breakdown($scope, Dimension::HourBank) as $row) {
            if ($row['key'] !== null) {
                $period[(int) $row['key']] = ['in_bank' => $row['in_bank_minutes'], 'overage' => $row['overage_minutes']];
            }
        }

        $open = $banks->filter(fn (HourBank $bank): bool => $bank->acceptsTime());
        $committed = $commitment->forBanks($open->modelKeys());

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
            $history->chains($banks),
        );

        return [
            'banks' => array_values(array_filter($rows, fn (array $row): bool => in_array($row['status'], ['active', 'exhausted'], true)
                || $row['period_in_bank_minutes'] + $row['period_overage_minutes'] > 0)),
            'all_banks' => array_values($rows),
            'history' => $chains,
        ];
    }

    /**
     * @param  array{projects: list<array{name: string, logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null, has_bank: bool}>, timeline: array{bucket: string, buckets: list<string>, series: list<array{key: string, name: string, total: int}>, cells: array<string, array<string, int>>}, all_banks: list<array<string, mixed>>}  $data
     */
    private function export(TableExporter $exporter, Client $client, ReportScope $scope, array $data, mixed $table, string $format): StreamedResponse
    {
        $table = is_string($table) && in_array($table, self::TABLES, true) ? $table : 'proyectos';
        $financials = $scope->canSeeFinancials();
        $name = self::text('reports.r2.client.export_name', ['client' => $client->name]).' '.$table;
        $c = fn (string $key): string => self::text('reports.r2.client.columns.'.$key);

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

            return $exporter->download($name, $headers, $rows, $format);
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

            return $exporter->download($name, $headers, $rows, $format);
        }

        $headers = [$c('project'), $c('logged'), $c('billable'), $c('in_bank'), $c('overage')];
        if ($financials) {
            array_push($headers, $c('income'), $c('cost'), $c('margin'));
        }

        // «Dentro de bolsa» solo en los proyectos con horas en bolsas (en los demás, vacío).
        $totals = ['logged' => 0, 'billable' => 0, 'in_bank' => 0, 'overage' => 0, 'income' => '0', 'cost' => '0'];
        $rows = [];
        foreach ($data['projects'] as $project) {
            $row = [$project['name'], TableExporter::hours($project['logged_minutes']), TableExporter::hours($project['billable_minutes']),
                $project['has_bank'] ? TableExporter::hours($project['in_bank_minutes']) : null, TableExporter::hours($project['overage_minutes'])];
            foreach (['logged', 'billable', 'in_bank', 'overage'] as $key) {
                $totals[$key] += $project[$key.'_minutes'];
            }
            if ($financials) {
                $income = (string) $project['income'];
                $cost = (string) $project['cost'];
                array_push($row, TableExporter::money($income), TableExporter::money($cost), TableExporter::money(Money::round(Money::sub($income, $cost))));
                $totals['income'] = Money::add($totals['income'], $income);
                $totals['cost'] = Money::add($totals['cost'], $cost);
            }
            $rows[] = $row;
        }

        $total = [self::text('reports.r2.total'), TableExporter::hours($totals['logged']), TableExporter::hours($totals['billable']),
            TableExporter::hours($totals['in_bank']), TableExporter::hours($totals['overage'])];
        if ($financials) {
            array_push($total, TableExporter::money(Money::round($totals['income'])), TableExporter::money(Money::round($totals['cost'])),
                TableExporter::money(Money::round(Money::sub($totals['income'], $totals['cost']))));
        }
        $rows[] = $total;

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
