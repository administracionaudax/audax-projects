<?php

namespace App\Domain\Billing;

use App\Enums\BillingService;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLine;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * El listado de facturas de Holded (D-406 y D-407): vistas por tarea, periodo, filtros, barra de
 * importes, orden y totales al pie, todo en la URL y agregado en SQL (igual en PostgreSQL y en
 * SQLite, siempre con orderBy y sin DISTINCT). La ficha usa la misma lista para «anterior» y
 * «siguiente» y para volver con los mismos filtros.
 *
 * Parámetros (todos opcionales):
 * - `vista`: todas (por defecto), por-cobrar, vencidas, sin-proyecto, borradores o rectificativas.
 * - `periodo`: anio, anio-anterior, trimestre, mes, 12-meses, todo o rango (con `desde` y `hasta`).
 *   Sin él, el año en curso en «Todas» y «Rectificativas» y todo en las vistas de trabajo (lo que
 *   queda por cobrar o enlazar no caduca con el año).
 * - `buscar` (número, cliente, contacto o concepto), `cliente` (id), `servicio[]` (BillingService).
 * - `cobro`: vencido, por-vencer o cobrado (la barra de importes).
 * - `orden` (fecha, numero, cliente, base, total, pendiente, vencimiento) y `dir` (asc o desc).
 * - Los de antes, como alias (D-385): `enlace=sin` es la vista «Sin proyecto», `tipo=credit_note`
 *   la de rectificativas y `estado=overdue|draft` las de vencidas y borradores; el resto (`estado`,
 *   `tipo=invoice`, `enlace=con`) sigue filtrando y se ve como un filtro más.
 * - `enlace=no-necesita`: las marcadas «No necesita proyecto» (D-431), que no salen en «Sin proyecto».
 */
final class InvoiceList
{
    public const int PER_PAGE = 50;

    public const array VIEWS = ['todas', 'por-cobrar', 'vencidas', 'sin-proyecto', 'borradores', 'rectificativas'];

    public const array PERIODS = ['anio', 'anio-anterior', 'trimestre', 'mes', '12-meses', 'todo', 'rango'];

    public const array SORTS = ['fecha', 'numero', 'cliente', 'base', 'total', 'pendiente', 'vencimiento'];

    public const array COLLECTIONS = ['vencido', 'por-vencer', 'cobrado'];

    /** Alias SQL de cada vista en el recuento de las pestañas. */
    private const array COUNT_ALIASES = [
        'todas' => 'v_todas',
        'por-cobrar' => 'v_por_cobrar',
        'vencidas' => 'v_vencidas',
        'sin-proyecto' => 'v_sin_proyecto',
        'borradores' => 'v_borradores',
        'rectificativas' => 'v_rectificativas',
    ];

    /** El catálogo de servicios (una consulta por lista, aunque el filtro se use en varias). */
    private ?InvoicingReport $catalog = null;

    /** Vistas que, sin periodo en la URL, miran el año en curso; las demás, todo. */
    private const array YEAR_VIEWS = ['todas', 'rectificativas'];

    /**
     * @param  list<BillingService>  $services
     * @param  'asc'|'desc'  $direction
     */
    private function __construct(
        public readonly string $view,
        public readonly ?string $period,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $search,
        public readonly ?int $clientId,
        public readonly array $services,
        public readonly ?string $collection,
        public readonly string $sort,
        public readonly string $direction,
        public readonly ?string $status,
        public readonly ?string $kind,
        public readonly ?string $link,
        public readonly CarbonImmutable $today,
    ) {}

    /**
     * Lee la URL sin dar error: lo que no se entiende se ignora (también en la ficha, que recibe la
     * misma query para «anterior» y «siguiente»).
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, ?CarbonImmutable $today = null): self
    {
        $string = fn (string $key): ?string => isset($query[$key]) && is_string($query[$key]) && trim($query[$key]) !== '' ? trim($query[$key]) : null;
        $date = fn (string $key): ?string => ($value = $string($key)) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
        $oneOf = fn (string $key, array $allowed): ?string => in_array($value = $string($key), $allowed, true) ? $value : null;

        $view = $oneOf('vista', self::VIEWS);
        $status = $oneOf('estado', CollectionStatus::values());
        $kind = $oneOf('tipo', HoldedDocumentKind::values());
        $link = $oneOf('enlace', ['con', 'sin', 'no-necesita']);

        // Los parámetros de antes que equivalen a una vista pasan a serlo.
        if ($view === null) {
            $view = match (true) {
                $link === 'sin' => 'sin-proyecto',
                $kind === HoldedDocumentKind::CreditNote->value => 'rectificativas',
                $status === CollectionStatus::Overdue->value => 'vencidas',
                $status === CollectionStatus::Draft->value => 'borradores',
                default => 'todas',
            };
        }
        $link = $view === 'sin-proyecto' && $link === 'sin' ? null : $link;
        $kind = $view === 'rectificativas' && $kind === HoldedDocumentKind::CreditNote->value ? null : $kind;
        $status = ($view === 'vencidas' && $status === CollectionStatus::Overdue->value) || ($view === 'borradores' && $status === CollectionStatus::Draft->value) ? null : $status;

        $from = $date('desde');
        $to = $date('hasta');
        $period = $oneOf('periodo', self::PERIODS) ?? ($from !== null || $to !== null ? 'rango' : null);
        if ($period === 'rango' && $from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $client = $string('cliente');
        $search = $string('buscar');
        $sort = $oneOf('orden', self::SORTS) ?? 'fecha';

        return new self(
            view: $view,
            period: $period,
            from: $period === 'rango' ? $from : null,
            to: $period === 'rango' ? $to : null,
            search: $search === null ? '' : mb_substr($search, 0, 120),
            clientId: $client !== null && ctype_digit($client) ? (int) $client : null,
            services: BillingService::fromQuery($query['servicio'] ?? []),
            collection: $oneOf('cobro', self::COLLECTIONS),
            sort: $sort,
            direction: match ($string('dir')) {
                'asc' => 'asc',
                'desc' => 'desc',
                default => self::defaultDirection($sort),
            },
            status: $status,
            kind: $kind,
            link: $link,
            today: $today ?? LocalTime::today(),
        );
    }

    /**
     * Cliente y número, de la A a la Z; fechas e importes, de más a menos.
     *
     * @return 'asc'|'desc'
     */
    public static function defaultDirection(string $sort): string
    {
        return in_array($sort, ['cliente', 'numero'], true) ? 'asc' : 'desc';
    }

    /**
     * El periodo de una vista: el de la URL o, sin él, el de la vista.
     *
     * @return array{key: string, from: string|null, to: string|null}
     */
    public function periodFor(string $view): array
    {
        $key = $this->period ?? (in_array($view, self::YEAR_VIEWS, true) ? 'anio' : 'todo');
        $today = $this->today;

        [$from, $to] = match ($key) {
            'anio' => [$today->startOfYear(), $today->endOfYear()],
            'anio-anterior' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'trimestre' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'mes' => [$today->startOfMonth(), $today->endOfMonth()],
            '12-meses' => [$today->subYear()->addDay(), $today],
            'rango' => [$this->from === null ? null : CarbonImmutable::parse($this->from), $this->to === null ? null : CarbonImmutable::parse($this->to)],
            default => [null, null],
        };

        return ['key' => $key, 'from' => $from?->toDateString(), 'to' => $to?->toDateString()];
    }

    /**
     * Las facturas del listado: filtros, vista, periodo y tramo de cobro, ya ordenadas.
     *
     * @return Builder<HoldedInvoice>
     */
    public function query(): Builder
    {
        $query = $this->filtered($this->view);
        $this->whereCollection($query);

        return $this->sorted($query);
    }

    /**
     * Número de facturas de cada vista (con su periodo y los mismos filtros), en una consulta.
     *
     * @return array<string, int>
     */
    public function viewCounts(): array
    {
        $select = '';
        $bindings = [];
        foreach (self::COUNT_ALIASES as $view => $alias) {
            [$sql, $values] = $this->viewSql($view);
            [$periodSql, $periodValues] = $this->periodSql($view);
            $select .= ($select === '' ? '' : ', ')."COALESCE(SUM(CASE WHEN ({$sql}){$periodSql} THEN 1 ELSE 0 END), 0) as {$alias}";
            array_push($bindings, ...$values, ...$periodValues);
        }

        $row = $this->base()->toBase()->selectRaw($select, $bindings)->first();
        $counts = [];
        foreach (self::COUNT_ALIASES as $view => $alias) {
            $counts[$view] = (int) ($row->{$alias} ?? 0);
        }

        return $counts;
    }

    /**
     * La barra de importes (con IVA, D-406) de la vista actual sin el tramo de cobro: lo vencido y lo
     * que está por vencer (lo pendiente de cada factura) y lo cobrado, con cuántas facturas tiene
     * cada tramo. Solo las facturas que cuentan (sin borradores ni anuladas, D-397).
     *
     * @return array<string, array{amount: string, count: int}>
     */
    public function collectionBar(): array
    {
        $today = $this->today->toDateString();
        $overdue = 'holded_invoices.pending_total > 0 AND holded_invoices.due_on IS NOT NULL AND holded_invoices.due_on < ?';
        $upcoming = 'holded_invoices.pending_total > 0 AND (holded_invoices.due_on IS NULL OR holded_invoices.due_on >= ?)';
        $collected = 'holded_invoices.paid_total > 0';

        $row = HoldedInvoice::countingIn($this->filtered($this->view))->toBase()->selectRaw(
            "COALESCE(SUM(CASE WHEN {$overdue} THEN CAST(ROUND(holded_invoices.pending_total * 100) AS BIGINT) ELSE 0 END), 0) as overdue_cents,"
            ." COALESCE(SUM(CASE WHEN {$overdue} THEN 1 ELSE 0 END), 0) as overdue_count,"
            ." COALESCE(SUM(CASE WHEN {$upcoming} THEN CAST(ROUND(holded_invoices.pending_total * 100) AS BIGINT) ELSE 0 END), 0) as upcoming_cents,"
            ." COALESCE(SUM(CASE WHEN {$upcoming} THEN 1 ELSE 0 END), 0) as upcoming_count,"
            ." COALESCE(SUM(CASE WHEN {$collected} THEN CAST(ROUND(holded_invoices.paid_total * 100) AS BIGINT) ELSE 0 END), 0) as collected_cents,"
            ." COALESCE(SUM(CASE WHEN {$collected} THEN 1 ELSE 0 END), 0) as collected_count",
            [$today, $today, $today, $today],
        )->first();

        $part = fn (string $key): array => [
            'amount' => InvoicingReport::money((int) ($row->{"{$key}_cents"} ?? 0)),
            'count' => (int) ($row->{"{$key}_count"} ?? 0),
        ];

        return ['vencido' => $part('overdue'), 'por-vencer' => $part('upcoming'), 'cobrado' => $part('collected')];
    }

    /**
     * Totales al pie de lo filtrado: base (sin IVA), total y pendiente (con IVA). Sin las anuladas ni
     * una rectificativa de una anulada (D-397); en «Borradores», los borradores.
     *
     * @return array{count: int, subtotal: string, total: string, pending: string}
     */
    public function totals(): array
    {
        $query = $this->filtered($this->view);
        $this->whereCollection($query);
        if ($this->view !== 'borradores') {
            $query = HoldedInvoice::countingIn($query);
        }

        $row = $query->toBase()->selectRaw(
            'COUNT(*) as count, COALESCE(SUM(CAST(ROUND(holded_invoices.subtotal * 100) AS BIGINT)), 0) as subtotal,'
            .' COALESCE(SUM(CAST(ROUND(holded_invoices.total * 100) AS BIGINT)), 0) as total,'
            .' COALESCE(SUM(CASE WHEN holded_invoices.pending_total > 0 THEN CAST(ROUND(holded_invoices.pending_total * 100) AS BIGINT) ELSE 0 END), 0) as pending',
        )->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'subtotal' => InvoicingReport::money((int) ($row->subtotal ?? 0)),
            'total' => InvoicingReport::money((int) ($row->total ?? 0)),
            'pending' => InvoicingReport::money((int) ($row->pending ?? 0)),
        ];
    }

    /**
     * La anterior y la siguiente de una factura en esta lista, y en qué página de 50 está (para que
     * la miga «Facturas» vuelva a ella). Una consulta de solo ids.
     *
     * @return array{previous: int|null, next: int|null, position: int|null, total: int, page: int}
     */
    public function neighbours(int $id): array
    {
        $ids = $this->query()->toBase()->pluck('holded_invoices.id')->map(fn (mixed $value): int => (int) $value)->values()->all();
        $index = array_search($id, $ids, true);

        if (! is_int($index)) {
            return ['previous' => null, 'next' => null, 'position' => null, 'total' => count($ids), 'page' => 1];
        }

        return [
            'previous' => $ids[$index - 1] ?? null,
            'next' => $ids[$index + 1] ?? null,
            'position' => $index + 1,
            'total' => count($ids),
            'page' => intdiv($index, self::PER_PAGE) + 1,
        ];
    }

    /**
     * Los filtros como los entiende la página (y la URL que los reproduce, sin la página).
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'vista' => $this->view,
            'periodo' => $this->period,
            'desde' => $this->from,
            'hasta' => $this->to,
            'buscar' => $this->search,
            'cliente' => $this->clientId,
            'servicio' => array_map(fn (BillingService $service): string => $service->value, $this->services),
            'cobro' => $this->collection,
            'orden' => $this->sort,
            'dir' => $this->direction,
            'estado' => $this->status,
            'tipo' => $this->kind,
            'enlace' => $this->link,
        ];
    }

    /**
     * La query de la URL que reproduce esta lista (solo lo que no es por defecto).
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'vista' => $this->view === 'todas' ? null : $this->view,
            'periodo' => $this->period,
            'desde' => $this->from,
            'hasta' => $this->to,
            'buscar' => $this->search === '' ? null : $this->search,
            'cliente' => $this->clientId,
            'servicio' => $this->services === [] ? null : array_map(fn (BillingService $service): string => $service->value, $this->services),
            'cobro' => $this->collection,
            'orden' => $this->sort === 'fecha' ? null : $this->sort,
            'dir' => $this->direction === self::defaultDirection($this->sort) ? null : $this->direction,
            'estado' => $this->status,
            'tipo' => $this->kind,
            'enlace' => $this->link,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Los filtros comunes a todas las vistas: búsqueda, cliente, servicio y los de antes.
     *
     * @return Builder<HoldedInvoice>
     */
    private function base(): Builder
    {
        return HoldedInvoice::query()
            ->when($this->search !== '', function (Builder $q): void {
                $like = '%'.mb_strtolower($this->search).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(holded_invoices.number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(holded_invoices.contact_name) LIKE ?', [$like])
                    ->orWhereHas('client', fn (Builder $c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('lines', fn (Builder $l) => $l->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($this->clientId !== null, fn (Builder $q) => $q->where('holded_invoices.client_id', $this->clientId))
            ->when($this->services !== [], fn (Builder $q) => $q->whereIn('holded_invoices.id', $this->serviceInvoiceIds()))
            ->when($this->status !== null, fn (Builder $q) => $q->where('holded_invoices.collection_status', $this->status))
            ->when($this->kind !== null, fn (Builder $q) => $q->where('holded_invoices.kind', $this->kind))
            ->when($this->link === 'con', fn (Builder $q) => $q->whereHas('links'))
            ->when($this->link === 'sin', fn (Builder $q) => $q->whereDoesntHave('links'))
            // Las marcadas «No necesita proyecto» (D-431): fuera de «Sin proyecto», se ven con este filtro.
            ->when($this->link === 'no-necesita', fn (Builder $q) => $q->whereNotNull('holded_invoices.no_project_needed_at'));
    }

    /**
     * Los filtros comunes, la condición de una vista y su periodo.
     *
     * @return Builder<HoldedInvoice>
     */
    private function filtered(string $view): Builder
    {
        [$sql, $bindings] = $this->viewSql($view);
        $query = $this->base()->whereRaw("({$sql})", $bindings);
        $period = $this->periodFor($view);

        return $query
            ->when($period['from'] !== null, fn (Builder $q) => $q->where('holded_invoices.issued_on', '>=', $period['from']))
            ->when($period['to'] !== null, fn (Builder $q) => $q->where('holded_invoices.issued_on', '<=', $period['to']));
    }

    /** Ids de las facturas con alguna línea de los servicios elegidos (subconsulta). */
    private function serviceInvoiceIds(): QueryBuilder
    {
        $lines = HoldedInvoiceLine::query()->toBase()->select('holded_invoice_lines.holded_invoice_id');

        $this->catalog ??= app(InvoicingReport::class);

        return $this->catalog->onlyServices($lines, $this->services);
    }

    /**
     * Condición SQL de una vista (con sus valores).
     *
     * @return array{0: literal-string, 1: list<mixed>}
     */
    private function viewSql(string $view): array
    {
        $draft = CollectionStatus::Draft->value;
        $cancelled = CollectionStatus::Cancelled->value;
        $issued = 'holded_invoices.is_draft = ? AND holded_invoices.collection_status <> ?';
        $pending = "{$issued} AND holded_invoices.collection_status <> ? AND holded_invoices.pending_total > 0";

        return match ($view) {
            'por-cobrar' => [$pending, [false, $draft, $cancelled]],
            'vencidas' => ["{$pending} AND holded_invoices.due_on IS NOT NULL AND holded_invoices.due_on < ?", [false, $draft, $cancelled, $this->today->toDateString()]],
            // Como el aviso de antes (D-388): también los borradores, que cuentan como previsto.
            // Sin las marcadas «No necesita proyecto» (D-431), que se ven con `enlace=no-necesita`.
            'sin-proyecto' => ['holded_invoices.collection_status <> ? AND holded_invoices.no_project_needed_at IS NULL AND NOT EXISTS (SELECT 1 FROM holded_invoice_links WHERE holded_invoice_links.holded_invoice_id = holded_invoices.id)', [$cancelled]],
            'borradores' => ['holded_invoices.is_draft = ? OR holded_invoices.collection_status = ?', [true, $draft]],
            'rectificativas' => ['holded_invoices.kind = ?', [HoldedDocumentKind::CreditNote->value]],
            default => [$issued, [false, $draft]],
        };
    }

    /**
     * El periodo de una vista como «AND …» para el recuento de las pestañas.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function periodSql(string $view): array
    {
        $period = $this->periodFor($view);
        $sql = '';
        $bindings = [];
        if ($period['from'] !== null) {
            $sql .= ' AND holded_invoices.issued_on >= ?';
            $bindings[] = $period['from'];
        }
        if ($period['to'] !== null) {
            $sql .= ' AND holded_invoices.issued_on <= ?';
            $bindings[] = $period['to'];
        }

        return [$sql, $bindings];
    }

    /**
     * El tramo de la barra de importes elegido.
     *
     * @param  Builder<HoldedInvoice>  $query
     */
    private function whereCollection(Builder $query): void
    {
        $today = $this->today->toDateString();

        match ($this->collection) {
            'vencido' => HoldedInvoice::countingIn($query)->where('holded_invoices.pending_total', '>', 0)
                ->whereNotNull('holded_invoices.due_on')->where('holded_invoices.due_on', '<', $today),
            'por-vencer' => HoldedInvoice::countingIn($query)->where('holded_invoices.pending_total', '>', 0)
                ->where(fn (Builder $q) => $q->whereNull('holded_invoices.due_on')->orWhere('holded_invoices.due_on', '>=', $today)),
            'cobrado' => HoldedInvoice::countingIn($query)->where('holded_invoices.paid_total', '>', 0),
            default => null,
        };
    }

    /**
     * Orden de la columna elegida; los vacíos (borradores sin número, sin vencimiento), siempre al
     * final, y el id para desempatar.
     *
     * @param  Builder<HoldedInvoice>  $query
     * @return Builder<HoldedInvoice>
     */
    private function sorted(Builder $query): Builder
    {
        $direction = $this->direction;

        $column = match ($this->sort) {
            'numero' => 'holded_invoices.number',
            'cliente' => 'LOWER(COALESCE((SELECT clients.name FROM clients WHERE clients.id = holded_invoices.client_id), holded_invoices.contact_name))',
            'base' => 'holded_invoices.subtotal',
            'total' => 'holded_invoices.total',
            'pendiente' => 'holded_invoices.pending_total',
            'vencimiento' => 'holded_invoices.due_on',
            default => 'holded_invoices.issued_on',
        };

        return $query
            ->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("{$column} {$direction}")
            ->orderBy('holded_invoices.id', $direction);
    }
}
