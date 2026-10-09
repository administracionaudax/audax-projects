<?php

namespace App\Domain\Billing;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Models\BillingDocument;
use App\Models\HoldedContact;
use App\Models\HoldedInvoice;
use App\Models\HourBank;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La portada «Resumen» de Facturación (I1, D-411): qué te deben, qué falta por facturar, qué falta
 * por revisar y cómo va el periodo. Solo con view-billing.
 *
 * - **Requiere atención** (a hoy, no depende del periodo): facturas vencidas (importe con IVA y la
 *   más antigua), facturas sin proyecto ni bolsa, contactos de Holded sin casar o por confirmar y
 *   bolsas abiertas por encima del 85 % (el «en riesgo» de la Weekly, D-188).
 * - **Cuatro cifras** en dos grupos: «Facturación (sin IVA)» con lo facturado en el periodo y su
 *   variación frente al mismo tramo del año anterior (InvoicingReport, D-404) y lo que queda por
 *   facturar (UnbilledReport, D-412); «Cobros (con IVA)», a hoy, con lo pendiente y lo vencido de
 *   todas las facturas.
 * - **Facturado y cobrado por mes** (con IVA, D-411): el total de lo facturado por mes de emisión y
 *   los cobros por su fecha, y el facturado del año anterior como referencia.
 * - **Por cobrar** (a hoy): la antigüedad por tramos y los cinco clientes que más deben.
 * - **Por facturar**: los cinco clientes con más por facturar en el periodo.
 *
 * Todo agregado en SQL (sumas en céntimos enteros, igual en PostgreSQL y en SQLite), con un número
 * fijo de consultas (tests/Feature/Performance/BillingSummaryPerformanceTest.php).
 */
final class BillingSummary
{
    /** Periodos de la portada (los del listado de facturas, sin «todo», D-406). */
    public const array PERIODS = ['anio', 'anio-anterior', 'trimestre', 'mes', '12-meses', 'rango'];

    public const int TOP = 5;

    public function __construct(
        private readonly InvoicingReport $invoicing,
        private readonly UnbilledReport $unbilled,
    ) {}

    /**
     * El periodo de la URL (el de InvoiceList; sin él o con «todo», el año en curso).
     *
     * @param  array<string, mixed>  $query
     * @return array{key: string, from: string, to: string}
     */
    public static function period(array $query, ?CarbonImmutable $today = null): array
    {
        $list = InvoiceList::fromQuery([...$query, 'vista' => 'todas'], $today);
        $period = $list->periodFor('todas');

        if (! in_array($period['key'], self::PERIODS, true) || $period['from'] === null || $period['to'] === null) {
            $today ??= LocalTime::today();

            return ['key' => 'anio', 'from' => $today->startOfYear()->toDateString(), 'to' => $today->endOfYear()->toDateString()];
        }

        return ['key' => $period['key'], 'from' => $period['from'], 'to' => $period['to']];
    }

    /**
     * La query de los informes (ReportFilters) del mismo periodo, para enlazar a Ventas y Por facturar.
     *
     * @param  array{key: string, from: string, to: string}  $period
     * @return array<string, string>
     */
    public static function reportQuery(array $period): array
    {
        return match ($period['key']) {
            'anio' => ['periodo' => 'anio'],
            'anio-anterior' => ['periodo' => 'anio', 'fecha' => $period['from']],
            'trimestre' => ['periodo' => 'trimestre'],
            'mes' => ['periodo' => 'mes'],
            default => ['periodo' => 'rango', 'desde' => $period['from'], 'hasta' => $period['to']],
        };
    }

    /**
     * @param  array{key: string, from: string, to: string}  $period
     * @return array<string, mixed>
     */
    public function summary(User $viewer, array $period, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $from = CarbonImmutable::parse($period['from']);
        $to = CarbonImmutable::parse($period['to']);
        $filters = new ReportFilters($period['key'] === 'anio' || $period['key'] === 'anio-anterior' ? ReportPeriod::Year : ReportPeriod::Range, $from, $to, compare: true);
        $query = new InvoicingQuery($filters, [], $today);

        $headline = $this->invoicing->headline($query);
        $unbilled = $this->unbilled->report(new ReportScope($viewer, $filters->withoutComparison()), true, $today);
        $receivable = $this->receivable($today);

        return [
            'period' => $period,
            'today' => $today->toDateString(),
            'previous_year' => $from->subYear()->format('Y'),
            'attention' => $this->attention($today, $receivable),
            'kpis' => [
                'invoiced' => $headline['invoiced'],
                'previous_invoiced' => $headline['previous_invoiced'],
                'variation_pct' => $headline['variation_pct'],
                'invoices' => $headline['count'],
                'unbilled' => $unbilled['totals']['amount'] ?? '0.00',
                'unbilled_clients' => $unbilled['totals']['clients'],
                'outstanding' => $receivable['outstanding'],
                'outstanding_count' => $receivable['outstanding_count'],
                'overdue' => $receivable['overdue'],
                'overdue_count' => $receivable['overdue_count'],
            ],
            'months' => $this->months($query, $from, $to),
            'receivable' => ['aging' => $receivable['aging'], 'clients' => $receivable['clients']],
            'unbilled' => ['clients' => UnbilledReport::top($unbilled, self::TOP), 'total_clients' => $unbilled['totals']['clients']],
            'report_query' => self::reportQuery($period),
        ];
    }

    /**
     * Lo que requiere atención (a hoy).
     *
     * @param  array{overdue: string, overdue_count: int, oldest_due: string|null}  $receivable
     * @return array<string, mixed>
     */
    private function attention(CarbonImmutable $today, array $receivable): array
    {
        $unlinked = ReviewInbox::unlinked()->toBase()
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(CAST(ROUND(holded_invoices.subtotal * 100) AS BIGINT)), 0) as cents')
            ->first();

        $contacts = HoldedContact::query()->toBase()
            ->leftJoinSub(HoldedInvoice::query()->toBase()->whereNotNull('holded_contact_id')
                ->selectRaw('holded_contact_id, COALESCE(SUM(CAST(ROUND(subtotal * 100) AS BIGINT)), 0) as cents')
                ->groupBy('holded_contact_id'), 'invoiced', 'invoiced.holded_contact_id', '=', 'holded_contacts.holded_id')
            ->where(fn ($q) => $q->where(fn ($open) => $open->whereNull('holded_contacts.client_id')->whereNull('holded_contacts.ignored_at'))
                ->orWhere('holded_contacts.match_method', HoldedContact::MATCH_APPROX))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(invoiced.cents), 0) as cents')
            ->first();

        $banks = HourBank::query()->toBase()
            ->join('projects', 'projects.id', '=', 'hour_banks.project_id')
            ->whereNull('hour_banks.deleted_at')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->where('projects.billing_type', '!=', BillingType::Internal->value)
            ->whereIn('hour_banks.status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value])
            ->where('hour_banks.total_minutes', '>', 0)
            ->whereRaw('hour_banks.consumed_minutes * 100 >= hour_banks.total_minutes * ?', [SoldVsActual::RISK_PCT])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(CASE WHEN hour_banks.consumed_minutes > hour_banks.total_minutes THEN 1 ELSE 0 END), 0) as over')
            ->first();

        $oldest = $receivable['oldest_due'];

        return [
            'overdue' => [
                'count' => $receivable['overdue_count'],
                'amount' => $receivable['overdue'],
                'oldest_days' => $oldest === null ? null : (int) CarbonImmutable::parse($oldest, 'UTC')->diffInDays(CarbonImmutable::parse($today->toDateString(), 'UTC')),
            ],
            'unlinked' => ['count' => (int) ($unlinked->count ?? 0), 'amount' => InvoicingReport::money((int) ($unlinked->cents ?? 0))],
            'contacts' => ['count' => (int) ($contacts->count ?? 0), 'amount' => InvoicingReport::money((int) ($contacts->cents ?? 0))],
            'banks' => ['count' => (int) ($banks->count ?? 0), 'over' => (int) ($banks->over ?? 0), 'threshold' => SoldVsActual::RISK_PCT],
        ];
    }

    /**
     * Lo pendiente de cobro a hoy (con IVA, de las facturas que cuentan, D-397): total, vencido, la
     * antigüedad por tramos y los clientes que más deben (tres consultas).
     *
     * @return array{outstanding: string, outstanding_count: int, overdue: string, overdue_count: int, oldest_due: string|null, aging: list<array{key: string, amount: string, count: int}>, clients: list<array{client: array{id: int, name: string}|null, contact_name: string|null, amount: string, count: int, overdue_count: int}>}
     */
    private function receivable(CarbonImmutable $today): array
    {
        $day = $today->toDateString();
        $pending = fn (): Builder => BillingDocument::countingIn(BillingDocument::query())->where('billing_documents.pending_total', '>', 0);

        $rows = DB::query()->fromSub($pending()->toBase()
            ->selectRaw("CASE WHEN billing_documents.due_on IS NULL OR billing_documents.due_on >= ? THEN 'current'"
                ." WHEN billing_documents.due_on >= ? THEN 'd1_30'"
                ." WHEN billing_documents.due_on >= ? THEN 'd31_60'"
                ." WHEN billing_documents.due_on >= ? THEN 'd61_90'"
                ." ELSE 'd90_plus' END as bucket, CAST(ROUND(billing_documents.pending_total * 100) AS BIGINT) as cents, billing_documents.due_on as due_on", [
                    $day, $today->subDays(30)->toDateString(), $today->subDays(60)->toDateString(), $today->subDays(90)->toDateString(),
                ]), 'pending')
            ->selectRaw('bucket, COALESCE(SUM(cents), 0) as cents, COUNT(*) as count, MIN(due_on) as oldest')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $aging = [];
        $total = 0;
        $count = 0;
        $overdue = 0;
        $overdueCount = 0;
        $oldest = null;
        foreach (InvoicingReport::AGING as $key) {
            $cents = (int) ($rows[$key]->cents ?? 0);
            $n = (int) ($rows[$key]->count ?? 0);
            $aging[] = ['key' => $key, 'amount' => InvoicingReport::money($cents), 'count' => $n];
            $total += $cents;
            $count += $n;
            if ($key !== 'current') {
                $overdue += $cents;
                $overdueCount += $n;
                $due = $rows[$key]->oldest ?? null;
                if ($due !== null && ($oldest === null || substr((string) $due, 0, 10) < $oldest)) {
                    $oldest = substr((string) $due, 0, 10);
                }
            }
        }

        $amount = 'CAST(ROUND(billing_documents.pending_total * 100) AS BIGINT)';
        $clients = $pending()->toBase()
            ->leftJoin('clients', 'clients.id', '=', 'billing_documents.client_id')
            ->selectRaw('billing_documents.client_id as client_id, clients.name as client_name, CASE WHEN billing_documents.client_id IS NULL THEN billing_documents.contact_name END as contact_name,'
                ." COALESCE(SUM({$amount}), 0) as cents, COUNT(*) as count,"
                .' COALESCE(SUM(CASE WHEN billing_documents.due_on IS NOT NULL AND billing_documents.due_on < ? THEN 1 ELSE 0 END), 0) as overdue_count', [$day])
            ->groupByRaw('billing_documents.client_id, clients.name, CASE WHEN billing_documents.client_id IS NULL THEN billing_documents.contact_name END')
            ->orderByRaw("COALESCE(SUM({$amount}), 0) DESC")
            ->orderBy('clients.name')
            ->limit(self::TOP)
            ->get();

        return [
            'outstanding' => InvoicingReport::money($total),
            'outstanding_count' => $count,
            'overdue' => InvoicingReport::money($overdue),
            'overdue_count' => $overdueCount,
            'oldest_due' => $oldest,
            'aging' => $aging,
            'clients' => array_values($clients->map(fn (object $row): array => [
                'client' => $row->client_id === null ? null : ['id' => (int) $row->client_id, 'name' => (string) $row->client_name],
                'contact_name' => $row->client_id === null ? (is_string($row->contact_name) ? $row->contact_name : null) : null,
                'amount' => InvoicingReport::money((int) $row->cents),
                'count' => (int) $row->count,
                'overdue_count' => (int) $row->overdue_count,
            ])->all()),
        ];
    }

    /**
     * Facturado (total con IVA, por mes de emisión) y cobrado (por la fecha del cobro) de cada mes
     * del periodo, y lo facturado el mismo mes del año anterior (tres consultas).
     *
     * @return list<array{month: string, invoiced: string, collected: string, previous: string}>
     */
    private function months(InvoicingQuery $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $month = 'SUBSTR(CAST(billing_documents.issued_on AS TEXT), 1, 7)';
        $invoiced = fn (CarbonImmutable $start, CarbonImmutable $end): array => BillingDocument::countingIn(BillingDocument::query())
            ->where('billing_documents.issued_on', '>=', $start->toDateString())
            ->where('billing_documents.issued_on', '<=', $end->toDateString())
            ->toBase()
            ->selectRaw("{$month} as month, COALESCE(SUM(CAST(ROUND(billing_documents.total * 100) AS BIGINT)), 0) as cents")
            ->groupByRaw($month)
            ->orderByRaw($month)
            ->pluck('cents', 'month')
            ->map(fn (mixed $cents): int => (int) $cents)
            ->all();

        $paidMonth = 'SUBSTR(CAST(holded_payments.paid_on AS TEXT), 1, 7)';
        $collected = DB::table('holded_payments')
            ->join('holded_invoices', 'holded_invoices.id', '=', 'holded_payments.holded_invoice_id')
            ->where('holded_payments.paid_on', '>=', $from->toDateString())
            ->where('holded_payments.paid_on', '<=', $to->toDateString())
            ->where('holded_invoices.is_draft', false)
            ->where('holded_invoices.collection_status', '!=', CollectionStatus::Cancelled->value)
            ->selectRaw("{$paidMonth} as month, COALESCE(SUM(CAST(ROUND(holded_payments.amount * 100) AS BIGINT)), 0) as cents")
            ->groupByRaw($paidMonth)
            ->orderByRaw($paidMonth)
            ->pluck('cents', 'month')
            ->map(fn (mixed $cents): int => (int) $cents)
            ->all();

        $current = $invoiced($from, $to);
        $previous = $invoiced($query->previousFrom(), $query->previousFullTo());

        $months = [];
        for ($cursor = $from->startOfMonth(); $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'month' => $key,
                'invoiced' => InvoicingReport::money($current[$key] ?? 0),
                'collected' => InvoicingReport::money($collected[$key] ?? 0),
                'previous' => InvoicingReport::money($previous[$cursor->subYearNoOverflow()->format('Y-m')] ?? 0),
            ];
        }

        return $months;
    }
}
