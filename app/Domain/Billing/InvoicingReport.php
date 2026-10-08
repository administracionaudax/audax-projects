<?php

namespace App\Domain\Billing;

use App\Domain\Reports\Money;
use App\Enums\BillingService;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Informe de facturación (D-400), como la «Analítica de ventas» de Holded: lo facturado, cobrado,
 * pendiente, vencido y previsto de un periodo, por mes, por servicio, por cliente y la antigüedad de
 * lo pendiente. Solo con view-billing (el controlador y el documento lo comprueban).
 *
 * - **Facturado** = base imponible (sin IVA) de lo que cuenta (HoldedInvoice::countingIn, D-397):
 *   emitidas aprobadas y no anuladas menos rectificativas, sin restar dos veces una anulada.
 * - **Cobrado**, **pendiente** y **vencido**, con IVA (lo que entra en el banco), de esas mismas
 *   facturas del periodo. Pendiente = lo que queda por cobrar de cada una (solo lo positivo); vencido,
 *   lo pendiente con el vencimiento antes de hoy. La antigüedad reparte lo pendiente por días de
 *   retraso a hoy.
 * - **Previsto** = base de los borradores de Holded del periodo (las recurrentes los crean el día 29,
 *   D-395): nunca es facturado.
 * - **Por servicio**: las líneas de esas facturas por BillingService (las de una rectificativa,
 *   siempre restando). Si la suma de las líneas no llega a la base (descuentos generales…), la
 *   diferencia sale como «Sin desglose por línea».
 * - **Con filtro de servicio**, lo facturado, lo previsto, el ranking y el mes salen de las líneas de
 *   ese servicio; cobrado, pendiente, vencido, antigüedad y número de facturas, de las facturas que
 *   lo llevan (el cobro es de la factura entera: no se reparte por línea).
 *
 * Todo se agrega en SQL (sumas en céntimos enteros, igual en PostgreSQL y en SQLite) y los importes
 * salen como cadenas con 2 decimales: nunca float.
 *
 * @phpstan-type InvoicingKpis array{invoiced: string, previous_invoiced: string, variation_pct: string|null, collected: string, outstanding: string, overdue: string, planned: string, planned_count: int, count: int, previous_count: int|null, average: string|null, previous_average: string|null, credit_notes: string}
 * @phpstan-type InvoicingMonth array{month: string, invoiced: string, planned: string, previous: string|null, count: int}
 * @phpstan-type InvoicingClients array{top: list<array{id: int, name: string, amount: string, share: string|null, count: int}>, rest: array{amount: string, share: string|null, clients: int, count: int}|null, unmatched: array{amount: string, share: string|null, count: int}|null}
 * @phpstan-type InvoicingOverdue array{clients: list<array{client: array{id: int, name: string}|null, contact_name: string|null, amount: string, invoices: list<array{id: int, number: string|null, issued_on: string, due_on: string, days: int, pending: string}>}>, total: int, shown: int}
 * @phpstan-type InvoicingData array{from: string, to: string, previous_from: string, previous_to: string, today: string, compare: bool, services_filter: list<string>, kpis: InvoicingKpis, months: list<InvoicingMonth>, services: list<array{key: string, amount: string, share: string|null}>, clients: InvoicingClients, aging: list<array{key: string, amount: string, count: int}>, overdue: InvoicingOverdue}
 */
final class InvoicingReport
{
    /** Clientes con nombre en el ranking; el resto se suma en «Resto». */
    public const int TOP_CLIENTS = 10;

    /** Facturas vencidas que se listan como mucho (las más antiguas primero). */
    public const int OVERDUE_LIMIT = 200;

    /** Tramos de la antigüedad de lo pendiente: sin vencer y días de retraso a hoy. */
    public const array AGING = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    /**
     * Pares (nombre, código) de las líneas, clasificados (una consulta por informe).
     *
     * @var list<array{name: string|null, code: string|null, service: BillingService}>|null
     */
    private ?array $catalog = null;

    /**
     * @return InvoicingData
     */
    public function report(InvoicingQuery $query, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $this->catalog = null;
        $from = $query->filters->from;
        $to = $query->filters->to;

        $current = $this->monthly($query, $from, $to, planned: false);
        // La gráfica enseña el año anterior entero; las cifras se comparan hasta el mismo día.
        $previous = $this->monthly($query, $query->previousFrom(), $query->previousFullTo(), planned: false);
        $comparable = $this->monthly($query, $query->previousFrom(), $query->previousTo(), planned: false);
        $planned = $this->monthly($query, $from, $to, planned: true);
        $collection = $this->collection($query);
        $aging = $this->aging($query, $today);

        $invoiced = array_sum(array_column($current, 'cents'));
        $previousInvoiced = array_sum(array_column($comparable, 'cents'));
        $count = (int) $collection['count'];
        $overdue = array_sum(array_map(fn (array $bucket): int => $bucket['key'] === 'current' ? 0 : $bucket['cents'], $aging));

        $months = [];
        foreach ($this->monthKeys($from, $to) as $month) {
            $before = CarbonImmutable::parse($month.'-01')->subYearNoOverflow()->format('Y-m');
            $months[] = [
                'month' => $month,
                'invoiced' => self::money($current[$month]['cents'] ?? 0),
                'planned' => self::money($planned[$month]['cents'] ?? 0),
                'previous' => $query->compares() ? self::money($previous[$before]['cents'] ?? 0) : null,
                'count' => $current[$month]['count'] ?? 0,
            ];
        }

        $previousCount = $query->compares() ? $this->collection($query, previous: true)['count'] : null;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $query->previousFrom()->toDateString(),
            'previous_to' => $query->previousTo()->toDateString(),
            'today' => $today->toDateString(),
            'compare' => $query->compares(),
            'services_filter' => array_map(fn (BillingService $service): string => $service->value, $query->services),
            'kpis' => [
                'invoiced' => self::money($invoiced),
                'previous_invoiced' => self::money($previousInvoiced),
                'variation_pct' => $previousInvoiced === 0 ? null : self::percent($invoiced - $previousInvoiced, abs($previousInvoiced)),
                'collected' => self::money((int) $collection['paid']),
                'outstanding' => self::money(array_sum(array_column($aging, 'cents'))),
                'overdue' => self::money($overdue),
                'planned' => self::money(array_sum(array_column($planned, 'cents'))),
                'planned_count' => array_sum(array_column($planned, 'count')),
                'count' => $count,
                'previous_count' => $previousCount,
                'average' => self::average($invoiced, $count),
                'previous_average' => $previousCount === null ? null : self::average($previousInvoiced, $previousCount),
                'credit_notes' => self::money($this->creditNotes($query)),
            ],
            'months' => $months,
            'services' => $this->services($query, $invoiced),
            'clients' => $this->clients($query, $invoiced),
            'aging' => array_map(fn (array $bucket): array => [
                'key' => $bucket['key'],
                'amount' => self::money($bucket['cents']),
                'count' => $bucket['count'],
            ], $aging),
            'overdue' => $this->overdue($query, $today),
        ];
    }

    /**
     * Importe en céntimos enteros como cadena con 2 decimales («-1234.50»).
     *
     * @return numeric-string
     */
    public static function money(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    /**
     * Facturas que cuentan (o borradores, con $planned) del periodo y de los clientes del filtro.
     *
     * @return Builder<HoldedInvoice>
     */
    private function invoices(InvoicingQuery $query, CarbonImmutable $from, CarbonImmutable $to, bool $planned = false): Builder
    {
        $builder = HoldedInvoice::query()
            ->where('holded_invoices.issued_on', '>=', $from->toDateString())
            ->where('holded_invoices.issued_on', '<', $to->addDay()->toDateString())
            ->when($query->filters->clientIds !== [], fn (Builder $q) => $q->whereIn('holded_invoices.client_id', $query->filters->clientIds));

        if ($planned) {
            return $builder->where(fn (Builder $q) => $q->where('holded_invoices.is_draft', true)
                ->orWhere('holded_invoices.collection_status', CollectionStatus::Draft->value));
        }

        return HoldedInvoice::countingIn($builder);
    }

    /**
     * Líneas de esas facturas, solo las de los servicios del filtro (si lo hay).
     */
    private function lines(InvoicingQuery $query, CarbonImmutable $from, CarbonImmutable $to, bool $planned = false): QueryBuilder
    {
        $lines = HoldedInvoiceLine::query()->toBase()
            ->join('holded_invoices', 'holded_invoices.id', '=', 'holded_invoice_lines.holded_invoice_id')
            ->whereIn('holded_invoice_lines.holded_invoice_id', $this->invoices($query, $from, $to, $planned)->select('holded_invoices.id'));

        return $query->services === [] ? $lines : $this->onlyServices($lines, $query->services);
    }

    /**
     * Restringe unas líneas a los servicios elegidos por sus pares (nombre, código).
     *
     * @param  list<BillingService>  $services
     */
    private function onlyServices(QueryBuilder $lines, array $services): QueryBuilder
    {
        $pairs = array_values(array_filter($this->catalog(), fn (array $pair): bool => in_array($pair['service'], $services, true)));

        if ($pairs === []) {
            return $lines->whereRaw('1 = 0');
        }

        return $lines->where(function (QueryBuilder $where) use ($pairs): void {
            foreach ($pairs as $pair) {
                $where->orWhere(function (QueryBuilder $one) use ($pair): void {
                    $pair['name'] === null ? $one->whereNull('holded_invoice_lines.name') : $one->where('holded_invoice_lines.name', $pair['name']);
                    $pair['code'] === null ? $one->whereNull('holded_invoice_lines.service_code') : $one->where('holded_invoice_lines.service_code', $pair['code']);
                });
            }
        });
    }

    /**
     * Los pares (nombre, código) distintos de todas las líneas con su servicio. Son pocos (el
     * catálogo de Holded): se clasifican en PHP, con tildes y mayúsculas fuera.
     *
     * @return list<array{name: string|null, code: string|null, service: BillingService}>
     */
    private function catalog(): array
    {
        return $this->catalog ??= array_values(HoldedInvoiceLine::query()->toBase()
            ->select(['name', 'service_code'])
            ->distinct()
            ->orderBy('name')
            ->orderBy('service_code')
            ->get()
            ->map(fn (object $row): array => [
                'name' => is_string($row->name ?? null) ? $row->name : null,
                'code' => is_string($row->service_code ?? null) ? $row->service_code : null,
                'service' => BillingService::classify($row->name ?? null, $row->service_code ?? null),
            ])->all());
    }

    /**
     * Suma en céntimos enteros de una expresión de importe.
     *
     * @param  literal-string  $expression
     * @return literal-string
     */
    private static function cents(string $expression): string
    {
        return "COALESCE(SUM(CAST(ROUND(({$expression}) * 100) AS BIGINT)), 0)";
    }

    /**
     * Importe de una línea con el signo del documento: las de una rectificativa siempre restan.
     *
     * @return literal-string
     */
    private static function lineAmount(): string
    {
        // 'credit_note' = HoldedDocumentKind::CreditNote (literal: va dentro del SQL).
        return "CASE WHEN holded_invoices.kind = 'credit_note' THEN -ABS(holded_invoice_lines.subtotal) ELSE holded_invoice_lines.subtotal END";
    }

    /**
     * Mes (AAAA-MM) de la fecha de emisión, igual en PostgreSQL y en SQLite.
     *
     * @return literal-string
     */
    private static function month(): string
    {
        return 'SUBSTR(CAST(holded_invoices.issued_on AS TEXT), 1, 7)';
    }

    /**
     * Facturas (no rectificativas) distintas.
     *
     * @return literal-string
     */
    private static function invoiceCount(): string
    {
        // 'invoice' = HoldedDocumentKind::Invoice (literal: va dentro del SQL).
        return "COUNT(DISTINCT CASE WHEN holded_invoices.kind = 'invoice' THEN holded_invoices.id END)";
    }

    /**
     * Lo facturado (o lo previsto) por mes: [AAAA-MM => [cents, count]].
     *
     * @return array<string, array{cents: int, count: int}>
     */
    private function monthly(InvoicingQuery $query, CarbonImmutable $from, CarbonImmutable $to, bool $planned): array
    {
        $month = self::month();
        $base = $query->services === []
            ? $this->invoices($query, $from, $to, $planned)->toBase()->selectRaw($month.' as month, '.self::cents('holded_invoices.subtotal').' as cents, '.self::invoiceCount().' as count')
            : $this->lines($query, $from, $to, $planned)->selectRaw($month.' as month, '.self::cents(self::lineAmount()).' as cents, '.self::invoiceCount().' as count');

        $rows = [];
        foreach ($base->groupByRaw($month)->orderByRaw($month)->get() as $row) {
            $rows[(string) $row->month] = ['cents' => (int) $row->cents, 'count' => (int) $row->count];
        }

        return $rows;
    }

    /**
     * Facturas del periodo (con el servicio, si se filtra) para el cobro: cobrado y número de facturas.
     *
     * @return array{paid: int, count: int}
     */
    private function collection(InvoicingQuery $query, bool $previous = false): array
    {
        $row = $this->collectionInvoices($query, $previous)->toBase()
            ->selectRaw(self::cents('holded_invoices.paid_total').' as paid, '.self::invoiceCount().' as count')
            ->first();

        return ['paid' => (int) ($row->paid ?? 0), 'count' => (int) ($row->count ?? 0)];
    }

    /**
     * @return Builder<HoldedInvoice>
     */
    private function collectionInvoices(InvoicingQuery $query, bool $previous = false): Builder
    {
        $from = $previous ? $query->previousFrom() : $query->filters->from;
        $to = $previous ? $query->previousTo() : $query->filters->to;
        $invoices = $this->invoices($query, $from, $to);

        if ($query->services === []) {
            return $invoices;
        }

        return $invoices->whereIn('holded_invoices.id', $this->lines($query, $from, $to)->select('holded_invoice_lines.holded_invoice_id'));
    }

    /** Lo que restan las rectificativas del periodo (céntimos, negativo o cero). */
    private function creditNotes(InvoicingQuery $query): int
    {
        $from = $query->filters->from;
        $to = $query->filters->to;
        $builder = $query->services === []
            ? $this->invoices($query, $from, $to)->toBase()->selectRaw(self::cents('holded_invoices.subtotal').' as cents')
            : $this->lines($query, $from, $to)->selectRaw(self::cents(self::lineAmount()).' as cents');

        return (int) ($builder->where('holded_invoices.kind', HoldedDocumentKind::CreditNote->value)->first()->cents ?? 0);
    }

    /**
     * Lo pendiente de cobro por tramos de retraso a hoy (con IVA; solo lo positivo).
     *
     * @return list<array{key: string, cents: int, count: int}>
     */
    private function aging(InvoicingQuery $query, CarbonImmutable $today): array
    {
        // El tramo de cada factura en una subconsulta (con sus fechas como parámetros) y la suma
        // por tramo fuera: PostgreSQL no reconoce como la misma expresión un CASE con parámetros en
        // el SELECT y en el GROUP BY. Retraso de 1 a 30 días = vencimiento entre hoy − 30 y ayer.
        $pending = $this->collectionInvoices($query)->toBase()
            ->where('holded_invoices.pending_total', '>', 0)
            ->selectRaw("CASE WHEN holded_invoices.due_on IS NULL OR holded_invoices.due_on >= ? THEN 'current'"
                ." WHEN holded_invoices.due_on >= ? THEN 'd1_30'"
                ." WHEN holded_invoices.due_on >= ? THEN 'd31_60'"
                ." WHEN holded_invoices.due_on >= ? THEN 'd61_90'"
                ." ELSE 'd90_plus' END as bucket, CAST(ROUND(holded_invoices.pending_total * 100) AS BIGINT) as cents", [
                    $today->toDateString(),
                    $today->subDays(30)->toDateString(),
                    $today->subDays(60)->toDateString(),
                    $today->subDays(90)->toDateString(),
                ]);

        $rows = DB::query()->fromSub($pending, 'pending')
            ->selectRaw('bucket, COALESCE(SUM(cents), 0) as cents, COUNT(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        return array_map(fn (string $key): array => [
            'key' => $key,
            'cents' => (int) ($rows[$key]->cents ?? 0),
            'count' => (int) ($rows[$key]->count ?? 0),
        ], self::AGING);
    }

    /**
     * Facturas vencidas (pendiente > 0 y vencimiento antes de hoy) por cliente: el que más debe
     * primero y, dentro, la más antigua primero.
     *
     * @return InvoicingOverdue
     */
    private function overdue(InvoicingQuery $query, CarbonImmutable $today): array
    {
        $base = $this->collectionInvoices($query)
            ->where('holded_invoices.pending_total', '>', 0)
            ->whereNotNull('holded_invoices.due_on')
            ->where('holded_invoices.due_on', '<', $today->toDateString());

        $total = (clone $base)->count();
        $rows = $base->toBase()
            ->leftJoin('clients', 'clients.id', '=', 'holded_invoices.client_id')
            ->select(['holded_invoices.id', 'holded_invoices.number', 'holded_invoices.issued_on', 'holded_invoices.due_on', 'holded_invoices.pending_total',
                'holded_invoices.client_id', 'holded_invoices.contact_name', 'clients.name as client_name'])
            ->orderBy('holded_invoices.due_on')
            ->orderBy('holded_invoices.id')
            ->limit(self::OVERDUE_LIMIT)
            ->get();

        // Días naturales entre fechas (sin horas ni husos: el 05/01 al 20/03 son 74 días).
        $todayUtc = CarbonImmutable::parse($today->toDateString(), 'UTC');
        $groups = [];
        foreach ($rows as $row) {
            $clientId = $row->client_id === null ? null : (int) $row->client_id;
            $key = $clientId === null ? 'contact:'.($row->contact_name ?? '') : 'client:'.$clientId;
            $due = CarbonImmutable::parse(substr((string) $row->due_on, 0, 10), 'UTC');
            $cents = (int) bcmul(Money::of($row->pending_total), '100', 0);

            $groups[$key] ??= [
                'client' => $clientId === null ? null : ['id' => $clientId, 'name' => (string) $row->client_name],
                'contact_name' => $row->contact_name,
                'cents' => 0,
                'invoices' => [],
            ];
            $groups[$key]['cents'] += $cents;
            $groups[$key]['invoices'][] = [
                'id' => (int) $row->id,
                'number' => $row->number,
                'issued_on' => substr((string) $row->issued_on, 0, 10),
                'due_on' => $due->toDateString(),
                'days' => (int) $due->diffInDays($todayUtc),
                'pending' => self::money($cents),
            ];
        }

        uasort($groups, fn (array $a, array $b): int => [$b['cents'], $a['client']['name'] ?? $a['contact_name'] ?? ''] <=> [$a['cents'], $b['client']['name'] ?? $b['contact_name'] ?? '']);

        return [
            'clients' => array_values(array_map(fn (array $group): array => [
                'client' => $group['client'],
                'contact_name' => $group['contact_name'],
                'amount' => self::money($group['cents']),
                'invoices' => $group['invoices'],
            ], $groups)),
            'total' => $total,
            'shown' => count($rows),
        ];
    }

    /**
     * Lo facturado por servicio, de mayor a menor (con su peso sobre lo facturado). Sin filtro de
     * servicio, la diferencia entre la base y sus líneas sale como «Sin desglose por línea».
     *
     * @return list<array{key: string, amount: string, share: string|null}>
     */
    private function services(InvoicingQuery $query, int $invoiced): array
    {
        $rows = $this->lines($query, $query->filters->from, $query->filters->to)
            ->selectRaw('holded_invoice_lines.name, holded_invoice_lines.service_code, '.self::cents(self::lineAmount()).' as cents')
            ->groupBy('holded_invoice_lines.name', 'holded_invoice_lines.service_code')
            ->orderBy('holded_invoice_lines.name')
            ->orderBy('holded_invoice_lines.service_code')
            ->get();

        $byService = [];
        foreach ($rows as $row) {
            $service = BillingService::classify($row->name ?? null, $row->service_code ?? null)->value;
            $byService[$service] = ($byService[$service] ?? 0) + (int) $row->cents;
        }

        $byService = array_filter($byService, fn (int $cents): bool => $cents !== 0);
        $rest = $invoiced - array_sum($byService);
        if ($query->services === [] && $rest !== 0) {
            $byService['sin_desglose'] = $rest;
        }

        // De mayor a menor; a igualdad, en el orden de los servicios.
        $order = array_flip(array_map(fn (BillingService $service): string => $service->value, BillingService::cases()));
        $sorted = [];
        foreach ($byService as $key => $cents) {
            $sorted[] = ['key' => (string) $key, 'cents' => $cents, 'order' => $order[$key] ?? count($order)];
        }
        usort($sorted, fn (array $a, array $b): int => [$b['cents'], $a['order']] <=> [$a['cents'], $b['order']]);

        return array_map(fn (array $row): array => [
            'key' => $row['key'],
            'amount' => self::money($row['cents']),
            'share' => self::share($row['cents'], $invoiced),
        ], $sorted);
    }

    /**
     * Ranking de clientes: los TOP_CLIENTS que más facturan, el resto sumado y las facturas sin
     * cliente casado (contactos de Holded sin cliente) aparte, con su enlace a los contactos.
     *
     * @return InvoicingClients
     */
    private function clients(InvoicingQuery $query, int $invoiced): array
    {
        $from = $query->filters->from;
        $to = $query->filters->to;
        $amount = $query->services === [] ? self::cents('holded_invoices.subtotal') : self::cents(self::lineAmount());
        $base = $query->services === [] ? $this->invoices($query, $from, $to)->toBase() : $this->lines($query, $from, $to);

        $rows = $base->leftJoin('clients', 'clients.id', '=', 'holded_invoices.client_id')
            ->selectRaw('holded_invoices.client_id, clients.name as client_name, '.$amount.' as cents, '.self::invoiceCount().' as count')
            ->groupBy('holded_invoices.client_id', 'clients.name')
            ->orderByRaw($amount.' DESC')
            ->orderBy('clients.name')
            ->get();

        $top = [];
        $rest = ['cents' => 0, 'clients' => 0, 'count' => 0];
        $unmatched = null;
        foreach ($rows as $row) {
            $cents = (int) $row->cents;
            if ($row->client_id === null) {
                $unmatched = ['amount' => self::money($cents), 'share' => self::share($cents, $invoiced), 'count' => (int) $row->count];
            } elseif (count($top) < self::TOP_CLIENTS) {
                $top[] = ['id' => (int) $row->client_id, 'name' => (string) $row->client_name, 'amount' => self::money($cents), 'share' => self::share($cents, $invoiced), 'count' => (int) $row->count];
            } else {
                $rest['cents'] += $cents;
                $rest['clients']++;
                $rest['count'] += (int) $row->count;
            }
        }

        return [
            'top' => $top,
            'rest' => $rest['clients'] === 0 ? null : ['amount' => self::money($rest['cents']), 'share' => self::share($rest['cents'], $invoiced), 'clients' => $rest['clients'], 'count' => $rest['count']],
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Ticket medio: lo facturado entre el número de facturas, redondeado al céntimo.
     *
     * @return numeric-string|null
     */
    private static function average(int $cents, int $count): ?string
    {
        return $count === 0 ? null : Money::round(Money::div(self::money($cents), (string) $count));
    }

    /** Peso en % con 1 decimal (null si no hay facturado). */
    private static function share(int $cents, int $total): ?string
    {
        return $total === 0 ? null : self::percent($cents, $total);
    }

    /**
     * $part sobre $total en %, redondeado a 1 decimal.
     *
     * @return numeric-string
     */
    private static function percent(int $part, int $total): string
    {
        return bcround(bcdiv((string) ($part * 100), (string) $total, 4), 1);
    }

    /**
     * Los meses (AAAA-MM) del periodo, en orden.
     *
     * @return list<string>
     */
    private function monthKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($month = $from->startOfMonth(); $month <= $to; $month = $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }
}
