<?php

namespace App\Domain\Billing;

use App\Domain\Reports\Cents;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Money;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\HoldedDocumentKind;
use App\Enums\HourBankStatus;
use App\Enums\InvoiceLineKind;
use App\Enums\ProjectStatus;
use App\Enums\SaleKind;
use App\Enums\TimeEntryStatus;
use App\Models\HoldedInvoice;
use App\Models\HoldedInvoiceLink;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Informe «Vendido frente a real» (Fase 12, F1; D-390; PLAN-FACTURACION §4.6). Una fila por unidad de
 * venta:
 *
 * | Unidad | Vendido (horas e importe) | Real | Facturas |
 * |---|---|---|---|
 * | Bolsa | `total_minutes` y `price_amount` | horas de la bolsa | las enlazadas con la bolsa |
 * | Precio cerrado | `budget_minutes` y `fixed_price_amount` | horas del proyecto | las del proyecto |
 * | Fee mensual | horas e importe al mes × meses del periodo | horas del periodo | las del periodo |
 * | Por horas | — (lo vendido es lo que se factura) | horas facturables del periodo | las del periodo |
 *
 * - Bolsas y precios cerrados se miden **enteros** (toda su vida) si están vivos en el periodo; fees
 *   y horas, **dentro del periodo**.
 * - **Real** = horas aprobadas o bloqueadas; las enviadas y en borrador van aparte («pendientes de
 *   aprobar»), como en todos los informes. Desviación = real − vendido; semáforo de la Weekly:
 *   riesgo desde el 85 %, pasado por encima del 100 %.
 * - **Importes** (solo con view-financials): lo facturado es la base imponible (sin IVA) de las
 *   facturas aprobadas y no anuladas menos sus rectificativas; cobrado y pendiente de cobro, con IVA
 *   (lo que se cobra). Una factura enlazada con varias unidades se reparte a partes iguales al
 *   céntimo (Cents). Ingreso = lo vendido (o, por horas, el valor de las horas a su tarifa congelada,
 *   RevenueCalculator); coste = el de las horas reales (instantáneas de coste); margen = ingreso −
 *   coste; precio efectivo = ingreso ÷ horas reales; pendiente de facturar = ingreso − facturado.
 *
 * Alcance: BillingAccess::projectScope (un gestor, solo sus proyectos). Las horas se suman de toda la
 * plantilla (son totales de la unidad, como el consumo de una bolsa), nunca por persona.
 */
final class SoldVsActual
{
    /** Umbrales del semáforo (los de la Weekly, D-188). */
    public const int RISK_PCT = 85;

    public const int OVER_PCT = 100;

    private const array REAL = [TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value];

    private const array PENDING = [TimeEntryStatus::Draft->value, TimeEntryStatus::Submitted->value];

    public function __construct(private readonly RevenueCalculator $revenue) {}

    /**
     * @return array{units: list<array<string, mixed>>, totals: array<string, mixed>, financials: bool, from: string, to: string}
     */
    public function report(SoldVsActualQuery $query, User $viewer, bool $financials): array
    {
        $from = $query->filters->from->startOfDay();
        $to = $query->filters->to->startOfDay();
        $projects = $this->projects($query, $viewer);

        $units = [];
        $bankProjects = $projects->filter(fn (Project $project): bool => $project->usesHourBanks());
        if ($query->wants(SaleKind::HourBank) && $bankProjects->isNotEmpty()) {
            $units = [...$units, ...$this->bankUnits($bankProjects, $query, $from, $to)];
        }

        if ($query->bankIds === []) {
            foreach ([SaleKind::FixedPrice, SaleKind::MonthlyFee, SaleKind::Hourly] as $kind) {
                if (! $query->wants($kind)) {
                    continue;
                }
                $ofKind = $projects->filter(fn (Project $project): bool => self::kindOf($project) === $kind);
                if ($ofKind->isNotEmpty()) {
                    $units = [...$units, ...$this->projectUnits($kind, $ofKind, $from, $to)];
                }
            }
        }

        $this->invoices($units, $from, $to);
        $this->costs($units, $from, $to);

        $rows = array_map(fn (array $unit): array => self::finish($unit, $financials), $units);
        usort($rows, fn (array $a, array $b): int => [self::statusOrder($a['status']), $a['client']['name'] ?? '', $a['project']['code'], $a['name']]
            <=> [self::statusOrder($b['status']), $b['client']['name'] ?? '', $b['project']['code'], $b['name']]);

        return [
            'units' => $rows,
            'totals' => self::totals($rows, $financials),
            'financials' => $financials,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /** Tipo de venta de un proyecto (los fees importados sin convertir siguen siendo «Por horas»). */
    public static function kindOf(Project $project): ?SaleKind
    {
        return match ($project->billing_type) {
            BillingType::HourBank => SaleKind::HourBank,
            BillingType::FixedPrice => SaleKind::FixedPrice,
            BillingType::MonthlyFee => SaleKind::MonthlyFee,
            BillingType::TimeAndMaterials => SaleKind::Hourly,
            BillingType::Internal => null,
        };
    }

    /** Estado del semáforo para un porcentaje de consumo. */
    public static function status(?float $pct): string
    {
        return match (true) {
            $pct === null => 'none',
            $pct > self::OVER_PCT => 'over',
            $pct >= self::RISK_PCT => 'risk',
            default => 'ok',
        };
    }

    /**
     * Meses naturales que tocan [$from, $to] (ambos incluidos).
     */
    public static function months(CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($to->lessThan($from)) {
            return 0;
        }

        return ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n') + 1;
    }

    /**
     * Proyectos con cliente (no internos) en el alcance de quien mira y con los filtros.
     *
     * @return Collection<int, Project>
     */
    private function projects(SoldVsActualQuery $query, User $viewer): Collection
    {
        $scope = BillingAccess::projectScope($viewer);

        return Project::query()
            ->with(['client:id,name', 'owner:id,name'])
            ->whereNotNull('client_id')
            ->where('billing_type', '!=', BillingType::Internal->value)
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('id', $scope === [] ? [0] : $scope))
            ->when($query->filters->clientIds !== [], fn (Builder $q) => $q->whereIn('client_id', $query->filters->clientIds))
            ->when($query->projectIds !== [], fn (Builder $q) => $q->whereIn('id', $query->projectIds))
            ->when($query->managerId !== null, fn (Builder $q) => $q->where('owner_user_id', $query->managerId))
            ->orderBy('code')
            ->get();
    }

    /**
     * Bolsas vivas en el periodo (o con horas en él), enteras.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function bankUnits(Collection $projects, SoldVsActualQuery $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byId = $projects->keyBy('id');
        $withEntries = TimeEntry::query()->whereIn('project_id', $byId->keys()->all())
            ->whereNotNull('hour_bank_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct()->pluck('hour_bank_id')->map(fn (mixed $id): int => (int) $id)->all();

        $banks = HourBank::query()
            ->whereIn('project_id', $byId->keys()->all())
            ->when($query->bankIds !== [], fn (Builder $q) => $q->whereIn('id', $query->bankIds))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $live) => $live->where('start_date', '<=', $to->toDateString())
                    ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString())))
                ->orWhereIn('id', $withEntries === [] ? [0] : $withEntries))
            ->orderBy('start_date')->orderBy('id')
            ->get();

        if ($banks->isEmpty()) {
            return [];
        }

        $hours = $this->hoursBy('hour_bank_id', TimeEntry::query()->whereIn('hour_bank_id', $banks->modelKeys()));

        return array_values($banks->map(function (HourBank $bank) use ($byId, $hours): array {
            /** @var Project $project */
            $project = $byId[$bank->project_id];

            return [
                'key' => 'bank:'.$bank->id,
                'kind' => SaleKind::HourBank,
                'project' => $project,
                'bank' => $bank,
                'name' => $bank->name,
                'whole' => true,
                'months' => null,
                'sold_minutes' => $bank->total_minutes,
                'sold_amount' => $bank->price_amount !== null ? (string) $bank->price_amount : null,
                'real_minutes' => $hours[$bank->id]['real'] ?? 0,
                'pending_minutes' => $hours[$bank->id]['pending'] ?? 0,
                'bank_status' => $bank->status,
            ];
        })->values()->all());
    }

    /**
     * Unidades de proyecto: precio cerrado (entero), fee (meses del periodo) y por horas (periodo).
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function projectUnits(SaleKind $kind, Collection $projects, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $projects->modelKeys();
        $whole = $kind === SaleKind::FixedPrice;

        $entries = TimeEntry::query()->whereIn('project_id', $ids);
        if (! $whole) {
            $entries->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        }
        if ($kind === SaleKind::Hourly) {
            $entries->where('is_billable', true);
        }
        $hours = $this->hoursBy('project_id', $entries);

        // Actividad en el periodo (horas o facturas): la de las unidades enteras se mide aparte.
        $inPeriod = $whole
            ? TimeEntry::query()->whereIn('project_id', $ids)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->distinct()->pluck('project_id')->map(fn (mixed $id): int => (int) $id)->all()
            : null;

        $invoiced = HoldedInvoiceLink::query()->whereIn('project_id', $ids)
            ->whereHas('invoice', fn (Builder $q) => $q->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()]))
            ->distinct()->pluck('project_id')->map(fn (mixed $id): int => (int) $id)->all();

        $units = [];
        foreach ($projects as $project) {
            $real = $hours[$project->id]['real'] ?? 0;
            $pending = $hours[$project->id]['pending'] ?? 0;
            $active = in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold, ProjectStatus::Planned], true)
                && ($project->start_date === null || $project->start_date->lessThanOrEqualTo($to))
                && ($project->due_date === null || $project->due_date->greaterThanOrEqualTo($from));

            $hasActivity = ($inPeriod !== null ? in_array($project->id, $inPeriod, true) : $real + $pending > 0)
                || in_array($project->id, $invoiced, true);
            if (! $hasActivity && ($kind === SaleKind::Hourly || ! $active)) {
                continue;
            }

            $unit = [
                'key' => 'project:'.$project->id,
                'kind' => $kind,
                'project' => $project,
                'bank' => null,
                'name' => $project->name,
                'whole' => $whole,
                'months' => null,
                'sold_minutes' => null,
                'sold_amount' => null,
                'real_minutes' => $real,
                'pending_minutes' => $pending,
                'bank_status' => null,
            ];

            if ($kind === SaleKind::FixedPrice) {
                $unit['sold_minutes'] = $project->budget_minutes;
                $unit['sold_amount'] = $project->fixed_price_amount !== null ? (string) $project->fixed_price_amount : null;
            } elseif ($kind === SaleKind::MonthlyFee) {
                $start = $from->max(($project->start_date ?? $from)->startOfMonth());
                $end = $to->min($project->due_date ?? $to);
                $months = self::months($start, $end);
                $unit['months'] = $months;
                $unit['sold_minutes'] = $project->monthly_minutes !== null ? $project->monthly_minutes * $months : null;
                $unit['sold_amount'] = $project->monthly_fee_amount !== null ? Money::round(Money::mul((string) $project->monthly_fee_amount, (string) $months)) : null;
            }

            $units[] = $unit;
        }

        return $units;
    }

    /**
     * Minutos reales (aprobados y bloqueados) y pendientes de aprobar por $column.
     *
     * @param  Builder<TimeEntry>  $entries
     * @return array<int, array{real: int, pending: int}>
     */
    private function hoursBy(string $column, Builder $entries): array
    {
        $select = $column === 'hour_bank_id' ? 'hour_bank_id as unit_id' : 'project_id as unit_id';

        $rows = (clone $entries)->toBase()
            ->selectRaw($select.', SUM(CASE WHEN status IN (?, ?) THEN minutes ELSE 0 END) as real_minutes, SUM(CASE WHEN status IN (?, ?) THEN minutes ELSE 0 END) as pending_minutes', [...self::REAL, ...self::PENDING])
            ->groupBy($column === 'hour_bank_id' ? 'hour_bank_id' : 'project_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->unit_id] = ['real' => (int) $row->real_minutes, 'pending' => (int) $row->pending_minutes];
        }

        return $result;
    }

    /**
     * Facturas de cada unidad: base facturada, total con IVA, cobrado y pendiente, repartidos al
     * céntimo entre las unidades de una misma factura.
     *
     * @param  list<array<string, mixed>>  $units
     */
    private function invoices(array &$units, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $zero = ['invoiced' => '0.00', 'invoiced_total' => '0.00', 'collected' => '0.00', 'outstanding' => '0.00', 'overdue' => '0.00', 'planned' => '0.00',
            'bank_amount' => '0.00', 'invoiced_minutes' => 0, 'invoice_ids' => []];
        foreach ($units as $index => $unit) {
            $units[$index] += $zero;
        }

        $projectIds = array_values(array_unique(array_map(fn (array $unit): int => $unit['project']->id, $units)));
        if ($projectIds === []) {
            return;
        }

        $links = HoldedInvoiceLink::query()->whereIn('project_id', $projectIds)
            ->with(['invoice:id,kind,issued_on,subtotal,total,paid_total,pending_total,collection_status,is_draft,rectified_invoice_id', 'invoice.lines:id,holded_invoice_id,name,service_code,units,subtotal', 'invoice.rectified:id,collection_status'])
            ->orderBy('id')->get()
            // Lo facturado y, aparte, los borradores (previsto, D-395); nunca lo anulado.
            ->filter(fn (HoldedInvoiceLink $link): bool => $link->invoice->counts() || $link->invoice->is_draft);

        // Unidad de cada enlace: la bolsa (si la hay) o el proyecto.
        $unitIndex = [];
        foreach ($units as $index => $unit) {
            $unitIndex[$unit['key']] = $index;
        }

        /** @var array<int, list<int>> $byInvoice factura → índices de unidad */
        $byInvoice = [];
        foreach ($links as $link) {
            $key = $link->hour_bank_id !== null ? 'bank:'.$link->hour_bank_id : 'project:'.$link->project_id;
            $index = $unitIndex[$key] ?? null;
            if ($index === null) {
                continue;
            }
            $unit = $units[$index];
            // Bolsas y precios cerrados: todas sus facturas; fees y horas: las del periodo.
            if (! $unit['whole'] && ($link->invoice->issued_on->lessThan($from) || $link->invoice->issued_on->greaterThan($to))) {
                continue;
            }
            if (! in_array($index, $byInvoice[$link->holded_invoice_id] ?? [], true)) {
                $byInvoice[$link->holded_invoice_id][] = $index;
            }
        }

        $invoices = $links->pluck('invoice')->keyBy('id');
        foreach ($byInvoice as $invoiceId => $indexes) {
            /** @var HoldedInvoice $invoice */
            $invoice = $invoices[$invoiceId];
            if ($invoice->is_draft) {
                foreach (self::split((string) $invoice->subtotal, count($indexes)) as $n => $amount) {
                    $units[$indexes[$n]]['planned'] = Money::round(Money::add($units[$indexes[$n]]['planned'], $amount));
                }

                continue;
            }

            // Lo que dicen sus líneas (D-396): horas facturadas (unidades de las líneas de horas y de
            // bolsa) y el importe de las líneas de bolsa (con su descuento).
            $minutes = 0;
            $bankAmount = '0';
            foreach ($invoice->lines as $line) {
                $kind = InvoiceLineKind::classify($line->service_code, $line->name, (string) $line->units);
                $sign = $invoice->kind === HoldedDocumentKind::CreditNote ? -1 : 1;
                if ($kind->countsHours()) {
                    $minutes += $sign * (int) round((float) $line->units * 60);
                }
                if ($kind === InvoiceLineKind::HourBank) {
                    $bankAmount = Money::add($bankAmount, Money::abs((string) $line->subtotal));
                }
            }
            $minuteShares = self::splitMinutes($minutes, count($indexes));

            $parts = [
                'bank_amount' => self::split(($invoice->kind === HoldedDocumentKind::CreditNote ? '-' : '').Money::round($bankAmount), count($indexes)),
                'invoiced' => self::split((string) $invoice->subtotal, count($indexes)),
                'invoiced_total' => self::split((string) $invoice->total, count($indexes)),
                'collected' => self::split((string) $invoice->paid_total, count($indexes)),
                'outstanding' => self::split((string) $invoice->pending_total, count($indexes)),
                'overdue' => self::split($invoice->collection_status === CollectionStatus::Overdue ? (string) $invoice->pending_total : '0.00', count($indexes)),
            ];
            foreach ($indexes as $n => $index) {
                foreach ($parts as $field => $amounts) {
                    $units[$index][$field] = Money::round(Money::add($units[$index][$field], $amounts[$n]));
                }
                $units[$index]['invoiced_minutes'] += $minuteShares[$n];
                $units[$index]['invoice_ids'][] = $invoiceId;
            }
        }
    }

    /**
     * Coste de las horas reales y, en las unidades por horas, su valor (ingreso) a la tarifa
     * congelada, con RevenueCalculator (las mismas fórmulas que el resto de informes).
     *
     * @param  list<array<string, mixed>>  $units
     */
    private function costs(array &$units, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $bankIds = [];
        $wholeProjects = [];
        $periodProjects = [];
        foreach ($units as $unit) {
            match (true) {
                $unit['bank'] !== null => $bankIds[] = $unit['bank']->id,
                $unit['whole'] => $wholeProjects[] = $unit['project']->id,
                default => $periodProjects[] = $unit['project']->id,
            };
        }

        $real = fn (): Builder => TimeEntry::query()->whereIn('time_entries.status', self::REAL);
        $byBank = $bankIds === [] ? [] : $this->revenue->exact($real()->whereIn('time_entries.hour_bank_id', $bankIds), Dimension::HourBank)['groups'];
        $byWhole = $wholeProjects === [] ? [] : $this->revenue->exact($real()->whereIn('time_entries.project_id', $wholeProjects), Dimension::Project)['groups'];
        $byPeriod = $periodProjects === [] ? [] : $this->revenue->exact($real()->whereIn('time_entries.project_id', $periodProjects)
            ->whereBetween('time_entries.date', [$from->toDateString(), $to->toDateString()]), Dimension::Project)['groups'];

        foreach ($units as $index => $unit) {
            $group = match (true) {
                $unit['bank'] !== null => $byBank[(string) $unit['bank']->id] ?? null,
                $unit['whole'] => $byWhole[(string) $unit['project']->id] ?? null,
                default => $byPeriod[(string) $unit['project']->id] ?? null,
            };
            $units[$index]['cost'] = Money::round($group['cost'] ?? '0');
            $units[$index]['hours_value'] = Money::round($group['income'] ?? '0');
        }
    }

    /**
     * Fila final: derivados y, sin view-financials, sin ningún importe.
     *
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private static function finish(array $unit, bool $financials): array
    {
        /** @var Project $project */
        $project = $unit['project'];
        /** @var SaleKind $kind */
        $kind = $unit['kind'];
        // Por horas, lo vendido son las horas facturadas (sus líneas de horas, D-396).
        if ($kind === SaleKind::Hourly && $unit['sold_minutes'] === null && $unit['invoiced_minutes'] > 0) {
            $unit['sold_minutes'] = $unit['invoiced_minutes'];
        }
        // Una bolsa sin precio en Audax (las de ClickUp): el de su línea «bolsadehoras» en Holded.
        $soldSource = $unit['sold_amount'] !== null ? 'audax' : null;
        if ($kind === SaleKind::HourBank && $unit['sold_amount'] === null && bccomp(Money::of($unit['bank_amount']), '0', 2) > 0) {
            $unit['sold_amount'] = $unit['bank_amount'];
            $soldSource = 'holded';
        }
        $sold = $unit['sold_minutes'];
        $real = (int) $unit['real_minutes'];
        $pct = $sold !== null && $sold > 0 ? round($real / $sold * 100, 1) : null;

        $row = [
            'key' => $unit['key'],
            'kind' => $kind->value,
            'name' => $unit['name'],
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'billing_type' => $project->billing_type->value],
            'client' => $project->client !== null ? ['id' => $project->client->id, 'name' => $project->client->name] : null,
            'manager' => ['id' => $project->owner->id, 'name' => $project->owner->name],
            'bank' => $unit['bank'] !== null ? ['id' => $unit['bank']->id, 'name' => $unit['bank']->name, 'status' => $unit['bank_status'] instanceof HourBankStatus ? $unit['bank_status']->value : null] : null,
            'whole' => $unit['whole'],
            'months' => $unit['months'],
            'sold_minutes' => $sold,
            'real_minutes' => $real,
            'pending_minutes' => (int) $unit['pending_minutes'],
            'deviation_minutes' => $sold !== null ? $real - $sold : null,
            'consumption_pct' => $pct,
            'status' => self::status($pct),
            'invoices_count' => count($unit['invoice_ids']),
            'invoiced_minutes' => (int) $unit['invoiced_minutes'],
        ];

        if (! $financials) {
            return $row;
        }

        $income = $kind === SaleKind::Hourly ? $unit['hours_value'] : $unit['sold_amount'];
        $margin = null;
        $marginPct = null;
        if ($income !== null) {
            $margin = Money::round(Money::sub($income, $unit['cost']));
            $marginPct = bccomp(Money::of($income), '0', 2) > 0 ? round((float) Money::div(Money::mul($margin, '100'), $income), 1) : null;
        }

        return $row + [
            'sold_amount' => $kind === SaleKind::Hourly ? null : $unit['sold_amount'],
            'sold_source' => $kind === SaleKind::Hourly ? null : $soldSource,
            'planned' => $unit['planned'],
            'hours_value' => $kind === SaleKind::Hourly ? $unit['hours_value'] : null,
            'invoiced' => $unit['invoiced'],
            'invoiced_total' => $unit['invoiced_total'],
            'collected' => $unit['collected'],
            'outstanding' => $unit['outstanding'],
            'overdue' => $unit['overdue'],
            'to_invoice' => $income !== null ? Money::round(self::positive(Money::sub($income, $unit['invoiced']))) : null,
            'cost' => $unit['cost'],
            'margin' => $margin,
            'margin_pct' => $marginPct,
            'effective_rate' => $income !== null && $real > 0 ? Money::round(Money::div(Money::mul($income, '60'), (string) $real)) : null,
        ];
    }

    /**
     * Totales: horas sumadas y, con importes, cada importe sumado (las filas ya van al céntimo).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function totals(array $rows, bool $financials): array
    {
        $soldRows = array_filter($rows, fn (array $row): bool => $row['sold_minutes'] !== null);
        $sold = array_sum(array_column($soldRows, 'sold_minutes'));
        $realOfSold = array_sum(array_column($soldRows, 'real_minutes'));
        $pct = $sold > 0 ? round($realOfSold / $sold * 100, 1) : null;

        $totals = [
            'units' => count($rows),
            'sold_minutes' => $sold,
            'real_minutes' => array_sum(array_column($rows, 'real_minutes')),
            'real_of_sold_minutes' => $realOfSold,
            'pending_minutes' => array_sum(array_column($rows, 'pending_minutes')),
            'deviation_minutes' => $realOfSold - $sold,
            'consumption_pct' => $pct,
            'status' => self::status($pct),
            'by_status' => [
                'over' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'over')),
                'risk' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'risk')),
                'ok' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'ok')),
                'none' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'none')),
            ],
        ];

        if (! $financials) {
            return $totals;
        }

        $sum = fn (string $field): string => Money::round(Money::add('0', ...array_map(fn (array $row): string => (string) ($row[$field] ?? '0'), $rows)));
        $income = Money::round(Money::add('0', ...array_map(fn (array $row): string => (string) ($row['sold_amount'] ?? $row['hours_value'] ?? '0'), $rows)));
        $cost = $sum('cost');
        $margin = Money::round(Money::sub($income, $cost));

        return $totals + [
            'sold_amount' => $sum('sold_amount'),
            'income' => $income,
            'invoiced' => $sum('invoiced'),
            'invoiced_total' => $sum('invoiced_total'),
            'collected' => $sum('collected'),
            'outstanding' => $sum('outstanding'),
            'overdue' => $sum('overdue'),
            'to_invoice' => $sum('to_invoice'),
            'planned' => $sum('planned'),
            'cost' => $cost,
            'margin' => $margin,
            'margin_pct' => bccomp($income, '0', 2) > 0 ? round((float) bcdiv(bcmul($margin, '100', 6), $income, 4), 1) : null,
        ];
    }

    /**
     * $amount en $parts partes al céntimo que suman exactamente $amount.
     *
     * @return list<string>
     */
    private static function split(string $amount, int $parts): array
    {
        if ($parts <= 1) {
            return [Money::round($amount)];
        }

        $negative = bccomp(Money::of($amount), '0', 2) < 0;
        $share = Money::div(Money::abs($amount), (string) $parts);
        $split = array_values(Cents::largestRemainder(array_fill(0, $parts, $share), Money::round(Money::abs($amount))));

        return $negative ? array_map(fn (string $value): string => Money::isZero($value) ? '0.00' : Money::round('-'.$value), $split) : $split;
    }

    /**
     * Minutos repartidos en $parts partes enteras que suman exactamente $minutes.
     *
     * @return list<int>
     */
    private static function splitMinutes(int $minutes, int $parts): array
    {
        $base = intdiv($minutes, max(1, $parts));
        $shares = array_fill(0, max(1, $parts), $base);
        for ($i = 0, $left = $minutes - $base * count($shares); $left !== 0; $i++, $left += $left > 0 ? -1 : 1) {
            $shares[$i % count($shares)] += $left > 0 ? 1 : -1;
        }

        return array_values($shares);
    }

    private static function positive(string $value): string
    {
        return bccomp(Money::of($value), '0', 6) > 0 ? $value : '0';
    }

    private static function statusOrder(string $status): int
    {
        return match ($status) {
            'over' => 0,
            'risk' => 1,
            'ok' => 2,
            default => 3,
        };
    }
}
