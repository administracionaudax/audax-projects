<?php

namespace App\Domain\Billing;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Money;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\InvoiceLineKind;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Por facturar» por cliente (I10, D-412), como el informe de lo no facturado de Harvest: lo que se
 * ha trabajado o vendido en un periodo y aún no se ha facturado. Cuatro fuentes:
 *
 * | Fuente | Horas | Importe (con view-billing) | Fecha más antigua |
 * |---|---|---|---|
 * | Proyectos por horas | aprobadas y facturables − las de las líneas de horas de sus facturas del periodo (D-396) | su valor a la tarifa congelada (RevenueCalculator) − lo facturado del periodo | la primera entrada |
 * | Excesos de bolsa | exceso del periodo − lo facturado de más en la bolsa | a la tarifa de la bolsa, el proyecto o el cliente | la primera entrada con exceso |
 * | Bolsas vendidas sin factura | — | su precio | su inicio |
 * | Fees del periodo sin factura | — | el importe al mes de cada mes ya empezado sin factura | el primer mes |
 *
 * Es la lectura de «Pendiente de facturar» de SoldVsActual (lo vendido o el valor de las horas menos
 * lo facturado), pero por cliente y sin contar lo que aún no ha llegado (meses futuros de un fee).
 * Sin view-billing (el módulo apagado, D-402) no se mira Holded: solo horas (las facturables
 * aprobadas del periodo y los excesos), sin un solo importe.
 *
 * Las horas salen de ReportScope::entries() (D-044): quien no es admin solo cuenta las que ve. Todo
 * agregado en SQL con un número fijo de consultas (tests/Feature/Performance).
 *
 * @phpstan-type UnbilledClient array{client: array{id: int, name: string, is_active: bool}, minutes: int, pending_minutes: int, amount: string|null, oldest: string|null, sources: array{hours: int, overage: int, banks: int, fees: int}}
 * @phpstan-type UnbilledData array{clients: list<UnbilledClient>, totals: array{clients: int, minutes: int, pending_minutes: int, amount: string|null}, financials: bool, from: string, to: string}
 */
final class UnbilledReport
{
    private const array REAL = [TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value];

    private const array PENDING = [TimeEntryStatus::Draft->value, TimeEntryStatus::Submitted->value];

    /** @var array<int, array{minutes: int, pending_minutes: int, amount: string, oldest: string|null, sources: array{hours: int, overage: int, banks: int, fees: int}}> */
    private array $rows = [];

    public function __construct(private readonly RevenueCalculator $revenue) {}

    /**
     * @return UnbilledData
     */
    public function report(ReportScope $scope, bool $financials, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $this->rows = [];
        $from = $scope->filters->from->startOfDay();
        $to = $scope->filters->to->startOfDay();

        $this->hourly($scope, $financials, $from, $to);
        $this->overage($scope, $financials);
        if ($financials) {
            $this->soldBanks($scope, $from, $to->min($today));
            $this->fees($scope, $from, $to->min($today));
        }

        $clients = Client::query()->withTrashed()->whereIn('id', array_keys($this->rows))->orderBy('id')->get(['id', 'name', 'is_active'])->keyBy('id');

        $list = [];
        foreach ($this->rows as $clientId => $row) {
            $client = $clients->get($clientId);
            $amount = Money::round($row['amount']);
            if ($client === null || ($row['minutes'] <= 0 && ! ($financials && bccomp($amount, '0', 2) > 0))) {
                continue;
            }
            $list[] = [
                'client' => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active],
                'minutes' => $row['minutes'],
                'pending_minutes' => $row['pending_minutes'],
                'amount' => $financials ? $amount : null,
                'oldest' => $row['oldest'],
                'sources' => $row['sources'],
            ];
        }

        // Primero quien más tiene por facturar (el importe o, sin importes, las horas).
        usort($list, fn (array $a, array $b): int => ($financials ? bccomp(Money::of($b['amount']), Money::of($a['amount']), 2) : 0)
            ?: ($b['minutes'] <=> $a['minutes'])
            ?: strcmp(mb_strtolower($a['client']['name']), mb_strtolower($b['client']['name'])));

        return [
            'clients' => $list,
            'totals' => [
                'clients' => count($list),
                'minutes' => array_sum(array_column($list, 'minutes')),
                'pending_minutes' => array_sum(array_column($list, 'pending_minutes')),
                'amount' => $financials ? Money::round(Money::add('0', ...array_map(fn (array $row): string => (string) $row['amount'], $list))) : null,
            ],
            'financials' => $financials,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * Proyectos por horas: horas facturables aprobadas del periodo menos las facturadas en él.
     */
    private function hourly(ReportScope $scope, bool $financials, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $projects = Project::query()->withTrashed()->select('id')->where('billing_type', BillingType::TimeAndMaterials->value)->whereNotNull('client_id');
        $entries = fn (): Builder => (clone $scope->entries())->where('time_entries.is_billable', true)->whereIn('time_entries.project_id', $projects);

        $rows = $entries()->toBase()
            ->selectRaw('time_entries.project_id as project_id,'
                .' SUM(CASE WHEN time_entries.status IN (?, ?) THEN time_entries.minutes ELSE 0 END) as real_minutes,'
                .' SUM(CASE WHEN time_entries.status IN (?, ?) THEN time_entries.minutes ELSE 0 END) as pending_minutes,'
                .' MIN(CASE WHEN time_entries.status IN (?, ?) THEN time_entries.date END) as oldest', [...self::REAL, ...self::PENDING, ...self::REAL])
            ->groupBy('time_entries.project_id')
            ->orderBy('time_entries.project_id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $ids = $rows->pluck('project_id')->map(fn (mixed $id): int => (int) $id)->all();
        $clientOf = Project::query()->withTrashed()->whereIn('id', $ids)->pluck('client_id', 'id')->map(fn (mixed $id): int => (int) $id);
        $income = [];
        if ($financials) {
            foreach ($this->revenue->exact($entries()->whereIn('time_entries.status', self::REAL), Dimension::Project)['groups'] as $key => $group) {
                $income[(int) $key] = $group['income'];
            }
        }
        $invoiced = $financials ? $this->invoiced(HoldedInvoiceLink::query()->whereIn('project_id', $ids)->whereNull('hour_bank_id'), 'project_id', $from, $to) : [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $clientId = $clientOf[$projectId] ?? null;
            if ($clientId === null) {
                continue;
            }
            $billed = $invoiced[$projectId] ?? ['minutes' => 0, 'amount' => '0'];
            $minutes = max(0, (int) $row->real_minutes - $billed['minutes']);
            $amount = $financials ? self::positive(Money::sub($income[$projectId] ?? '0', $billed['amount'])) : '0';
            $pending = (int) $row->pending_minutes;

            if ($minutes <= 0 && bccomp($amount, '0', 2) <= 0 && $pending <= 0) {
                continue;
            }

            $this->add($clientId, $minutes, $pending, $amount, $minutes > 0 || bccomp($amount, '0', 2) > 0 ? self::date($row->oldest) : null, 'hours', $minutes > 0 ? 1 : 0);
        }
    }

    /**
     * Excesos de bolsa del periodo, menos lo que la bolsa ya ha facturado por encima de lo vendido.
     */
    private function overage(ReportScope $scope, bool $financials): void
    {
        $rows = (clone $scope->entries())->toBase()
            ->whereNotNull('time_entries.hour_bank_id')
            ->whereIn('time_entries.status', self::REAL)
            ->where('time_entries.overage_minutes', '>', 0)
            ->selectRaw('time_entries.hour_bank_id as bank_id, SUM(time_entries.overage_minutes) as overage, MIN(time_entries.date) as oldest')
            ->groupBy('time_entries.hour_bank_id')
            ->orderBy('time_entries.hour_bank_id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $banks = HourBank::query()->withTrashed()->whereIn('id', $rows->pluck('bank_id')->map(fn (mixed $id): int => (int) $id)->all())
            ->with(['project' => fn ($q) => $q->withTrashed()->select(['id', 'client_id', 'hourly_rate']), 'project.client' => fn ($q) => $q->withTrashed()->select(['id', 'default_hourly_rate'])])
            ->orderBy('id')->get(['id', 'project_id', 'total_minutes', 'hourly_rate'])->keyBy('id');
        // Lo facturado de cada bolsa en toda su vida (las horas de sus líneas, D-396).
        $invoiced = $financials ? $this->invoiced(HoldedInvoiceLink::query()->whereIn('hour_bank_id', $banks->keys()->all()), 'hour_bank_id', null, null) : [];

        foreach ($rows as $row) {
            /** @var HourBank|null $bank */
            $bank = $banks->get((int) $row->bank_id);
            $clientId = $bank?->project->client_id;
            if ($bank === null || $clientId === null) {
                continue;
            }
            $billedExtra = max(0, ($invoiced[$bank->id]['minutes'] ?? 0) - $bank->total_minutes);
            $minutes = max(0, (int) $row->overage - $billedExtra);
            if ($minutes <= 0) {
                continue;
            }
            $rate = $this->revenue->rate($bank, $bank->project, $bank->project->client, null);
            $amount = $financials && $rate !== null ? Money::forMinutes($minutes, $rate) : '0';

            $this->add($clientId, $minutes, 0, $amount, self::date($row->oldest), 'overage', 1);
        }
    }

    /**
     * Bolsas con precio que empezaron en el periodo y no tienen ninguna factura enlazada.
     */
    private function soldBanks(ReportScope $scope, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lessThan($from)) {
            return;
        }

        $banks = HourBank::query()
            ->join('projects', 'projects.id', '=', 'hour_banks.project_id')
            ->whereNull('projects.deleted_at')
            ->whereNotNull('projects.client_id')
            ->when($scope->filters->clientIds !== [], fn (Builder $q) => $q->whereIn('projects.client_id', $scope->filters->clientIds))
            ->whereNotNull('hour_banks.price_amount')
            ->where('hour_banks.price_amount', '>', 0)
            ->whereBetween('hour_banks.start_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($links) => $links->selectRaw('1')->from('holded_invoice_links')
                ->join('holded_invoices', 'holded_invoices.id', '=', 'holded_invoice_links.holded_invoice_id')
                ->whereColumn('holded_invoice_links.hour_bank_id', 'hour_banks.id')
                ->where('holded_invoices.collection_status', '!=', CollectionStatus::Cancelled->value))
            ->orderBy('hour_banks.start_date')->orderBy('hour_banks.id')
            ->get(['hour_banks.id', 'hour_banks.price_amount', 'hour_banks.start_date', 'projects.client_id as bank_client_id']);

        foreach ($banks as $bank) {
            $this->add((int) $bank->getAttribute('bank_client_id'), 0, 0, (string) $bank->price_amount, $bank->start_date->toDateString(), 'banks', 1);
        }
    }

    /**
     * Fees mensuales: cada mes ya empezado del periodo (dentro de la vida del proyecto) sin una
     * factura enlazada emitida ese mes.
     */
    private function fees(ReportScope $scope, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lessThan($from)) {
            return;
        }

        $projects = Project::query()
            ->where('billing_type', BillingType::MonthlyFee->value)
            ->whereNotNull('client_id')
            ->whereNotNull('monthly_fee_amount')
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->when($scope->filters->clientIds !== [], fn (Builder $q) => $q->whereIn('client_id', $scope->filters->clientIds))
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $to->toDateString()))
            ->where(fn (Builder $q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $from->toDateString()))
            ->orderBy('id')
            ->get(['id', 'client_id', 'start_date', 'due_date', 'monthly_fee_amount']);

        if ($projects->isEmpty()) {
            return;
        }

        // Meses (AAAA-MM) con alguna factura (no anulada ni borrador) de cada fee.
        $invoicedMonths = [];
        $links = HoldedInvoiceLink::query()->toBase()
            ->join('holded_invoices', 'holded_invoices.id', '=', 'holded_invoice_links.holded_invoice_id')
            ->whereIn('holded_invoice_links.project_id', $projects->modelKeys())
            ->where('holded_invoices.is_draft', false)
            ->whereNotIn('holded_invoices.collection_status', [CollectionStatus::Cancelled->value, CollectionStatus::Draft->value])
            ->where('holded_invoices.kind', HoldedDocumentKind::Invoice->value)
            ->where('holded_invoices.issued_on', '>=', $from->startOfMonth()->toDateString())
            ->where('holded_invoices.issued_on', '<=', $to->endOfMonth()->toDateString())
            ->selectRaw('holded_invoice_links.project_id as project_id, SUBSTR(CAST(holded_invoices.issued_on AS TEXT), 1, 7) as month')
            ->orderBy('holded_invoice_links.project_id')
            ->get();
        foreach ($links as $link) {
            $invoicedMonths[(int) $link->project_id][(string) $link->month] = true;
        }

        foreach ($projects as $project) {
            $start = $from->max(($project->start_date ?? $from)->startOfMonth())->startOfMonth();
            $end = $to->min($project->due_date ?? $to);
            $missing = [];
            for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonthNoOverflow()) {
                if (! isset($invoicedMonths[$project->id][$month->format('Y-m')])) {
                    $missing[] = $month;
                }
            }
            if ($missing === [] || $project->client_id === null) {
                continue;
            }

            $amount = Money::mul((string) $project->monthly_fee_amount, (string) count($missing));
            $this->add($project->client_id, 0, 0, $amount, $missing[0]->toDateString(), 'fees', count($missing));
        }
    }

    /**
     * Lo facturado de unos enlaces por proyecto o bolsa: la base y las horas de las líneas de horas
     * y de bolsa (D-396), repartidas a partes iguales si la factura tiene varios enlaces. Solo las
     * facturas que cuentan (D-397) y, con fechas, las emitidas en ellas.
     *
     * @param  Builder<HoldedInvoiceLink>  $links
     * @return array<int, array{minutes: int, amount: string}>
     */
    private function invoiced(Builder $links, string $key, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $rows = $links
            ->whereHas('invoice', fn (Builder $q) => HoldedInvoice::countingIn($q)
                ->when($from !== null, fn (Builder $w) => $w->where('issued_on', '>=', $from?->toDateString()))
                ->when($to !== null, fn (Builder $w) => $w->where('issued_on', '<=', $to?->toDateString())))
            ->with(['invoice' => fn ($q) => $q->select(['id', 'kind', 'subtotal'])->withCount('links'), 'invoice.lines:id,holded_invoice_id,name,service_code,units'])
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($rows as $link) {
            $invoice = $link->invoice;
            $parts = max(1, (int) $invoice->getAttribute('links_count'));
            $sign = $invoice->kind === HoldedDocumentKind::CreditNote ? -1 : 1;
            $minutes = 0;
            foreach ($invoice->lines as $line) {
                if (InvoiceLineKind::classify($line->service_code, $line->name, (string) $line->units)->countsHours()) {
                    $minutes += $sign * (int) round((float) $line->units * 60);
                }
            }
            $id = (int) $link->getAttribute($key);
            $result[$id] ??= ['minutes' => 0, 'amount' => '0'];
            $result[$id]['minutes'] += intdiv($minutes, $parts);
            $result[$id]['amount'] = Money::add($result[$id]['amount'], Money::div((string) $invoice->subtotal, (string) $parts));
        }

        return $result;
    }

    /**
     * @param  'hours'|'overage'|'banks'|'fees'  $source
     */
    private function add(int $clientId, int $minutes, int $pending, string $amount, ?string $date, string $source, int $count): void
    {
        $row = $this->rows[$clientId] ?? ['minutes' => 0, 'pending_minutes' => 0, 'amount' => '0', 'oldest' => null, 'sources' => ['hours' => 0, 'overage' => 0, 'banks' => 0, 'fees' => 0]];
        $row['minutes'] += $minutes;
        $row['pending_minutes'] += $pending;
        $row['amount'] = Money::add($row['amount'], $amount);
        if ($date !== null && ($row['oldest'] === null || $date < $row['oldest'])) {
            $row['oldest'] = $date;
        }
        $row['sources'][$source] += $count;

        $this->rows[$clientId] = $row;
    }

    /**
     * @return numeric-string
     */
    private static function positive(string $value): string
    {
        $value = Money::of($value);

        return bccomp($value, '0', 6) > 0 ? $value : '0';
    }

    private static function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : substr((string) $value, 0, 10);
    }

    /**
     * Los N clientes con más por facturar (la portada, I1).
     *
     * @param  UnbilledData  $report
     * @return list<UnbilledClient>
     */
    public static function top(array $report, int $count): array
    {
        return array_slice($report['clients'], 0, $count);
    }
}
