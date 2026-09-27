<?php

namespace App\Domain\Reports\Pdf;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\EstimateComparison;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos del PDF de consumo de una bolsa (SPEC §10 «Exportación», D-045; R2), pensado para
 * enviárselo al cliente: el consumo por mes y el listado llevan SOLO las horas aprobadas o
 * bloqueadas (como hará el portal, SPEC §11), de toda la vida de la bolsa, con su parte dentro y
 * su exceso (overage_minutes), así que cuadran entre sí.
 *
 * El saldo restante es SIEMPRE el de la bolsa (HourBankLedger: una sola fuente para el saldo, la
 * barra y la regla `block`), que cuenta las horas en cualquier estado: el exceso se asigna por
 * fecha, así que una entrada sin aprobar anterior puede dejar en exceso una aprobada posterior. Para
 * que las cifras cuadren (aprobadas dentro + sin aprobar dentro + restante = total), el PDF enseña
 * aparte lo que va dentro de la bolsa o en exceso sin estar en el listado (pending_*).
 *
 * Las horas salen de ReportScope::entries() (D-044): un gestor del proyecto o un admin ven todas;
 * un responsable que no gestiona el proyecto, solo las de su equipo (el PDF lo avisa: partial).
 * Los datos económicos (precio, tarifa e ingreso estimado, D-043) solo si se piden
 * ($withFinancials, «PDF con importes (uso interno)») y quien lo descarga tiene view-financials:
 * el PDF normal es el que se envía al cliente y nunca los lleva.
 * Las cifras y el listado se guardan en ReportCache (D-046); la fecha del informe, no.
 */
final class HourBankStatement
{
    public function __construct(
        private readonly RevenueCalculator $revenue,
        private readonly ReportCache $cache,
    ) {}

    /**
     * @return array{
     *     company: string, client: string|null, project: array{code: string, name: string},
     *     bank: array{name: string, start_date: string, end_date: string|null, status: string, total_minutes: int},
     *     generated_at: CarbonImmutable,
     *     figures: array{consumed: int, in_bank: int, overage: int, pending_in_bank: int, pending_overage: int, remaining: int, ratio: float},
     *     months: list<array{month: string, in_bank: int, overage: int}>,
     *     entries: list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>,
     *     financials: array{price_amount: string|null, rate: string|null, income: string}|null,
     *     partial: bool
     * }
     */
    public function build(User $viewer, HourBank $bank, bool $withFinancials = false): array
    {
        /** @var Project $project */
        $project = Project::query()->withTrashed()->findOrFail($bank->project_id, ['id', 'code', 'name', 'client_id', 'hourly_rate', 'billing_type']);
        /** @var Client|null $client */
        $client = $project->client_id !== null
            ? Client::query()->withTrashed()->find($project->client_id, ['id', 'name', 'default_hourly_rate'])
            : null;

        $scope = new ReportScope($viewer, new ReportFilters(
            period: ReportPeriod::Range,
            from: CarbonImmutable::parse(EstimateComparison::LIFETIME_FROM),
            to: CarbonImmutable::parse(EstimateComparison::LIFETIME_TO),
            projectIds: [$project->id],
            bankIds: [$bank->id],
        ));

        $approved = fn (): Builder => (clone $scope->entries())
            ->whereIn('time_entries.status', [TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value]);
        $financials = $withFinancials && $scope->canSeeFinancials();

        /** @var array{consumed: int, overage: int, months: list<array{month: string, in_bank: int, overage: int}>, entries: list<array{date: string, person: string, task: string, in_bank: int, overage: int, description: string}>, income: string|null} $data */
        $data = $this->cache->remember($scope, 'r2.bank-pdf.'.$bank->id.($financials ? '.f' : ''), function () use ($approved, $financials): array {
            $totals = $approved()->toBase()
                ->selectRaw('COALESCE(SUM(time_entries.minutes), 0) as minutes, COALESCE(SUM(time_entries.overage_minutes), 0) as overage')
                ->first();

            $month = Dimension::Month->expression();
            $months = array_values($approved()->toBase()
                ->selectRaw($month.' as month, SUM(time_entries.minutes) as minutes, SUM(time_entries.overage_minutes) as overage')
                ->groupByRaw($month)
                ->orderByRaw($month)
                ->get()
                ->map(fn (object $row): array => [
                    'month' => substr((string) $row->month, 0, 10),
                    'in_bank' => (int) $row->minutes - (int) $row->overage,
                    'overage' => (int) $row->overage,
                ])
                ->all());

            // Filas sin hidratar, con la persona y la tarea por LEFT JOIN (una consulta).
            $detail = $approved();
            Dimension::ensureJoin($detail, 'users');
            Dimension::ensureJoin($detail, 'tasks');
            $entries = array_values($detail->toBase()
                ->select(['time_entries.date', 'time_entries.minutes', 'time_entries.overage_minutes', 'time_entries.description',
                    'report_users.name as person', 'report_tasks.title as task'])
                ->orderBy('time_entries.date')
                ->orderBy('time_entries.id')
                ->get()
                ->map(fn (object $row): array => [
                    'date' => substr((string) $row->date, 0, 10),
                    'person' => (string) $row->person,
                    'task' => (string) $row->task,
                    'in_bank' => (int) $row->minutes - (int) $row->overage_minutes,
                    'overage' => (int) $row->overage_minutes,
                    'description' => (string) $row->description,
                ])
                ->all());

            return [
                'consumed' => (int) ($totals->minutes ?? 0),
                'overage' => (int) ($totals->overage ?? 0),
                'months' => $months,
                'entries' => $entries,
                'income' => $financials ? ($this->revenue->compute($approved())['all']['income'] ?? '0.00') : null,
            ];
        });

        $consumed = $data['consumed'];
        $overage = $data['overage'];
        $inBank = $consumed - $overage;
        // La bolsa entera (HourBankLedger), en cualquier estado: lo que no sale en el listado va aparte.
        $bankInBank = $bank->in_bank_minutes;

        return [
            'company' => (string) Setting::get('company_name', Setting::DEFAULTS['company_name']),
            'client' => $client?->name,
            'project' => ['code' => $project->code, 'name' => $project->name],
            'bank' => [
                'name' => $bank->name,
                'start_date' => $bank->start_date->toDateString(),
                'end_date' => $bank->end_date?->toDateString(),
                'status' => $bank->status->label(),
                'total_minutes' => $bank->total_minutes,
            ],
            'generated_at' => LocalTime::now(),
            'figures' => [
                'consumed' => $consumed,
                'in_bank' => $inBank,
                'overage' => $overage,
                'pending_in_bank' => max($bankInBank - $inBank, 0),
                'pending_overage' => max($bank->overage_minutes - $overage, 0),
                'remaining' => $bank->remaining_minutes,
                'ratio' => $bank->total_minutes > 0 ? round($consumed / $bank->total_minutes, 4) : 0.0,
            ],
            'months' => $data['months'],
            'entries' => $data['entries'],
            'financials' => $financials ? [
                'price_amount' => $bank->price_amount,
                'rate' => $this->revenue->rate($bank, $project, $client, null),
                'income' => $data['income'] ?? '0.00',
            ] : null,
            'partial' => ! $viewer->isAdmin() && ! $viewer->isManagerOf($project),
        ];
    }
}
