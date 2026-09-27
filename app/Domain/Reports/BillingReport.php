<?php

namespace App\Domain\Reports;

use App\Enums\BillingType;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Horas para facturar (SPEC §10 «Exportación», D-045; R2): de un alcance ya acotado (normalmente
 * un cliente y un periodo) da
 * - el resumen por proyecto y bolsa: minutos dentro de la bolsa y en exceso por separado,
 *   facturables, no facturables y pendientes de aprobar (borrador o enviadas) y, con
 *   view-financials, la tarifa de referencia y el ingreso estimado (RevenueCalculator, D-043),
 * - el detalle de cada entrada, valorada una a una con EntryValuation (mismo criterio).
 * Céntimos: el ingreso total se redondea una sola vez (la suma exacta de los grupos) y se reparte
 * entre las filas del resumen por resto mayor y entre las entradas del detalle en orden (Cents): la
 * página y la exportación dan el mismo total y sus filas siempre suman ese total.
 * Todo sale de ReportScope::entries(): respeta quién ve qué horas (D-044).
 */
final class BillingReport
{
    public const string PRICING_BANK_PRICE = 'bank_price';

    public const string PRICING_HOURLY = 'hourly';

    public const string PRICING_PERSON = 'person_rates';

    public const string PRICING_FIXED = 'fixed_price';

    public const string PRICING_INTERNAL = 'internal';

    public function __construct(private readonly RevenueCalculator $revenue) {}

    /**
     * @return array{rows: list<array{project: array{id: int, code: string, name: string, billing_type: string},
     *     bank: array{id: int, name: string, status: string}|null, logged_minutes: int, in_bank_minutes: int, overage_minutes: int,
     *     billable_minutes: int, non_billable_minutes: int, pending_minutes: int, pricing: string|null, rate: string|null,
     *     price_amount: string|null, income: string|null}>,
     *     totals: array{entries: int, logged_minutes: int, in_bank_minutes: int, overage_minutes: int, billable_minutes: int,
     *     non_billable_minutes: int, pending_minutes: int, income: string|null}}
     */
    public function summary(ReportScope $scope): array
    {
        $pending = [TimeEntryStatus::Draft->value, TimeEntryStatus::Submitted->value];
        $groups = (clone $scope->entries())->toBase()
            ->selectRaw('time_entries.project_id as project_id, time_entries.hour_bank_id as hour_bank_id,
                COUNT(*) as entries,
                SUM(time_entries.minutes) as logged,
                SUM(time_entries.overage_minutes) as overage,
                SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END) as billable,
                SUM(CASE WHEN time_entries.status IN (?, ?) THEN time_entries.minutes ELSE 0 END) as pending', $pending)
            ->groupBy('time_entries.project_id', 'time_entries.hour_bank_id')
            ->get();

        $financials = $scope->canSeeFinancials();
        $totals = ['entries' => 0, 'logged_minutes' => 0, 'in_bank_minutes' => 0, 'overage_minutes' => 0, 'billable_minutes' => 0,
            'non_billable_minutes' => 0, 'pending_minutes' => 0, 'income' => $financials ? '0.00' : null];

        if ($groups->isEmpty()) {
            return ['rows' => [], 'totals' => $totals];
        }

        $projects = Project::query()->withTrashed()
            ->whereIn('id', $groups->pluck('project_id')->map(fn ($id): int => (int) $id)->unique()->values())
            ->get(['id', 'client_id', 'code', 'name', 'billing_type', 'hourly_rate', 'fixed_price_amount'])
            ->keyBy('id');
        $banks = HourBank::query()->withTrashed()
            ->whereIn('id', $groups->pluck('hour_bank_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values())
            ->get(['id', 'project_id', 'name', 'status', 'start_date', 'hourly_rate', 'price_amount', 'total_minutes'])
            ->keyBy('id');
        $clients = $financials
            ? Client::query()->withTrashed()->whereIn('id', $projects->pluck('client_id')->filter()->unique()->values())
                ->get(['id', 'default_hourly_rate'])->keyBy('id')
            : collect();

        /** @var array<int, string> $bankIncome ingreso exacto por bolsa */
        $bankIncome = [];
        /** @var array<int, string> $projectIncome ingreso exacto de las horas sin bolsa, por proyecto */
        $projectIncome = [];
        if ($financials) {
            foreach ($this->revenue->computeExact((clone $scope->entries())->whereNotNull('time_entries.hour_bank_id'), Dimension::HourBank) as $key => $money) {
                $bankIncome[(int) $key] = $money['income'];
            }
            foreach ($this->revenue->computeExact((clone $scope->entries())->whereNull('time_entries.hour_bank_id'), Dimension::Project) as $key => $money) {
                $projectIncome[(int) $key] = $money['income'];
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            /** @var Project|null $project */
            $project = $projects->get((int) $group->project_id);
            if ($project === null) {
                continue;
            }

            /** @var HourBank|null $bank */
            $bank = $group->hour_bank_id !== null ? $banks->get((int) $group->hour_bank_id) : null;
            $logged = (int) $group->logged;
            $overage = (int) $group->overage;
            $billable = (int) $group->billable;

            $row = [
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'billing_type' => $project->billing_type->value],
                'bank' => $bank === null ? null : ['id' => $bank->id, 'name' => $bank->name, 'status' => $bank->status->value],
                'logged_minutes' => $logged,
                'in_bank_minutes' => $bank === null ? 0 : $logged - $overage,
                'overage_minutes' => $overage,
                'billable_minutes' => $billable,
                'non_billable_minutes' => $logged - $billable,
                'pending_minutes' => (int) $group->pending,
                'pricing' => null,
                'rate' => null,
                'price_amount' => null,
                'income' => null,
                '_sort' => [$project->code, $bank === null ? 1 : 0, $bank === null ? '' : $bank->start_date->toDateString(), $bank === null ? '' : $bank->name],
            ];

            if ($financials) {
                /** @var Client|null $client */
                $client = $project->client_id !== null ? $clients->get($project->client_id) : null;
                $rate = $this->revenue->rate($bank, $project, $client, null);
                $row['pricing'] = self::pricing($project, $bank, $rate);
                $row['rate'] = $project->billing_type === BillingType::FixedPrice ? null : $rate;
                $row['price_amount'] = match (true) {
                    $project->billing_type === BillingType::FixedPrice => $project->fixed_price_amount,
                    $bank !== null => $bank->price_amount,
                    default => null,
                };
                $row['income'] = $bank !== null ? ($bankIncome[$bank->id] ?? '0') : ($projectIncome[$project->id] ?? '0');
            }

            $totals['entries'] += (int) $group->entries;
            foreach (['logged_minutes', 'in_bank_minutes', 'overage_minutes', 'billable_minutes', 'non_billable_minutes', 'pending_minutes'] as $key) {
                $totals[$key] += $row[$key];
            }

            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);

        // Un solo redondeo del total y sus céntimos repartidos entre las filas (resto mayor).
        $shares = [];
        if ($financials) {
            $exact = array_map(fn (array $row): string => (string) $row['income'], $rows);
            $totals['income'] = Money::round(Money::add('0', ...$exact));
            $shares = Cents::largestRemainder($exact);
        }

        return [
            'rows' => array_map(function (array $row, int $index) use ($shares): array {
                unset($row['_sort']);
                $row['income'] = $shares[$index] ?? $row['income'];

                return $row;
            }, $rows, array_keys($rows)),
            'totals' => $totals,
        ];
    }

    /**
     * Entradas del alcance para el detalle (fecha e id ascendentes), con los nombres de la persona,
     * el proyecto, la bolsa y la tarea en la misma consulta (filas sin hidratar, por lotes de 1.000:
     * miles de entradas en poco tiempo y memoria), su valoración con view-financials y su importe en
     * céntimos (amount): los importes suman $total (el ingreso del resumen) si se indica; si no, el
     * redondeo de su suma exacta.
     *
     * @return Generator<int, array{entry: array{id: int, date: string, person: string, project_code: string, project_name: string,
     *     bank: string|null, task: string, description: string, minutes: int, overage_minutes: int, is_billable: bool,
     *     status: TimeEntryStatus}, valuation: array{rate: string|null, income: string, basis: string}|null, amount: numeric-string|null}>
     */
    public function entries(ReportScope $scope, ?string $total = null): Generator
    {
        $lines = Cents::running(
            $this->valuedEntries($scope),
            fn (array $line): ?string => $line['valuation']['income'] ?? null,
            $total,
        );

        foreach ($lines as [$line, $amount]) {
            yield [...$line, 'amount' => $amount];
        }
    }

    /**
     * @return Generator<int, array{entry: array{id: int, date: string, person: string, project_code: string, project_name: string,
     *     bank: string|null, task: string, description: string, minutes: int, overage_minutes: int, is_billable: bool,
     *     status: TimeEntryStatus}, valuation: array{rate: string|null, income: numeric-string, basis: string}|null}>
     */
    private function valuedEntries(ReportScope $scope): Generator
    {
        $valuation = $scope->canSeeFinancials() ? EntryValuation::for($scope->entries()) : null;

        foreach ($this->detailQuery($scope)->lazy(1000) as $row) {
            $billable = (bool) $row->is_billable;
            $minutes = (int) $row->minutes;
            $overage = (int) $row->overage_minutes;
            $bankId = $row->hour_bank_id === null ? null : (int) $row->hour_bank_id;

            yield [
                'entry' => [
                    'id' => (int) $row->id,
                    'date' => substr((string) $row->date, 0, 10),
                    'person' => (string) $row->person_name,
                    'project_code' => (string) $row->project_code,
                    'project_name' => (string) $row->project_name,
                    'bank' => $bankId === null ? null : (string) $row->bank_name,
                    'task' => (string) $row->task_title,
                    'description' => (string) $row->description,
                    'minutes' => $minutes,
                    'overage_minutes' => $overage,
                    'is_billable' => $billable,
                    'status' => TimeEntryStatus::from((string) $row->status),
                ],
                'valuation' => $valuation?->valueOf((int) $row->project_id, $bankId, (int) $row->user_id, $billable, $minutes, $overage, $row->hourly_rate_snapshot),
            ];
        }
    }

    /**
     * Detalle sin hidratar: las columnas de la entrada y los nombres por LEFT JOIN (los mismos alias
     * que Dimension::ensureJoin; las tareas y bolsas borradas conservan su nombre).
     */
    private function detailQuery(ReportScope $scope): QueryBuilder
    {
        $query = clone $scope->entries();
        foreach (['users', 'projects', 'hour_banks', 'tasks'] as $table) {
            Dimension::ensureJoin($query, $table);
        }

        return $query->toBase()
            ->select(['time_entries.id', 'time_entries.user_id', 'time_entries.project_id', 'time_entries.hour_bank_id',
                'time_entries.date', 'time_entries.minutes', 'time_entries.overage_minutes', 'time_entries.description',
                'time_entries.is_billable', 'time_entries.status', 'time_entries.hourly_rate_snapshot',
                'report_users.name as person_name', 'report_projects.code as project_code', 'report_projects.name as project_name',
                'report_hour_banks.name as bank_name', 'report_tasks.title as task_title'])
            ->orderBy('time_entries.date')
            ->orderBy('time_entries.id');
    }

    /**
     * Cómo se valora el grupo (solo con view-financials): precio de bolsa, tarifa por horas (de la
     * bolsa, el proyecto o el cliente), tarifas de cada persona, precio cerrado o interno.
     */
    private static function pricing(Project $project, ?HourBank $bank, ?string $rate): string
    {
        return match (true) {
            $project->billing_type === BillingType::Internal => self::PRICING_INTERNAL,
            $project->billing_type === BillingType::FixedPrice => self::PRICING_FIXED,
            $bank !== null && $bank->price_amount !== null && $bank->total_minutes > 0 => self::PRICING_BANK_PRICE,
            $rate === null => self::PRICING_PERSON,
            default => self::PRICING_HOURLY,
        };
    }
}
