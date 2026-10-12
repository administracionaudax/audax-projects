<?php

namespace App\Domain\Billing\Work;

use App\Domain\Billing\Issuing\DraftWriter;
use App\Domain\Billing\UnbilledReport;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\Money;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\BillingService;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\TaxRateKind;
use App\Enums\TaxRegime;
use App\Enums\TimeEntryStatus;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\PaymentMethod;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentLink;
use App\Models\SalesDocumentTimeEntry;
use App\Models\TaxRate;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Crear borrador» desde el trabajo (PLAN-EMISION §3.8; E3): una factura en borrador de un cliente
 * con lo que se ha elegido de lo que tiene por facturar (UnbilledWork), con un título por proyecto
 * cuando hay varios (H-070):
 *
 * - Horas, agrupadas como se eligió, a su tarifa congelada y al céntimo (WorkLines::quantityFor).
 *   Cada línea guarda sus minutos y sus entradas (`sales_document_time_entry`): al **emitir** se
 *   bloquean con la factura como referencia (TimeLockService::lockForDocument), al **anular** se
 *   desbloquean y al rectificar por diferencias no (D-244). Una entrada solo puede estar en una
 *   factura viva (índice único parcial): no se factura dos veces.
 * - Bolsas («bolsadehoras», horas × €/h), excesos (a su tarifa, con sus entradas), fees («Fee de
 *   [mes]», con su mes) y precios cerrados (un importe o hito, como mucho lo pendiente).
 * - La factura queda enlazada a cada proyecto y bolsa (`sales_document_links`, «desde el trabajo»),
 *   así cuenta en «Vendido frente a real». El exceso de una bolsa renovada que va con la siguiente
 *   se enlaza a la siguiente (D-433).
 *
 * Lo que ya no se puede facturar cuando se pulsa (otra persona lo puso en otra factura, se
 * desaprobó…) da un error y no crea nada.
 *
 * @phpstan-import-type DraftLine from DraftWriter
 *
 * @phpstan-type WorkSelection array{entries?: list<int>, overage_entries?: list<int>, banks?: list<int>, fees?: list<array{project_id: int, month: string}>, fixed?: list<array{project_id: int, amount: string, description?: string|null}>}
 * @phpstan-type WorkItem array{service_id: int|null, description: string, quantity: string, unit: string, unit_price: string, discount_pct: string}
 * @phpstan-type WorkMeta array{origin: string, project_id: int, hour_bank_id: int|null, link_bank_id?: int, minutes: int|null, period_start?: string, entries: list<int>}
 */
final class WorkDraft
{
    /** @var array<int, list<array{line: DraftLine, meta: WorkMeta}>> proyecto → sus líneas */
    private array $items = [];

    private ?int $tax = null;

    public function __construct(
        private readonly DraftWriter $writer,
        private readonly RevenueCalculator $revenue,
    ) {}

    /**
     * @param  WorkSelection  $selection
     *
     * @throws ValidationException
     */
    public function create(Client $client, array $selection, string $grouping, User $by): SalesDocument
    {
        $grouping = in_array($grouping, UnbilledWork::GROUPINGS, true) ? $grouping : 'task';
        $projects = Project::query()->withTrashed()->where('client_id', $client->id)->get()->keyBy('id');
        $services = UnbilledWork::services();
        $tax = self::tax($client);

        $this->items = [];
        $this->tax = $tax;

        // Horas de los proyectos por horas.
        $entries = $this->entries($selection['entries'] ?? [], $projects, false);
        if ($entries->isNotEmpty()) {
            $valuation = EntryValuation::for(TimeEntry::query()->whereKey($entries->modelKeys()));
            foreach ($entries->groupBy('project_id') as $projectId => $own) {
                foreach (UnbilledWork::group($own->values(), $grouping, $valuation, $services) as $group) {
                    $price = $group['rate'] ?? '0';
                    $this->add((int) $projectId, [
                        'service_id' => $group['service_id'],
                        'description' => $group['description'],
                        'quantity' => WorkLines::quantityFor($group['minutes'], $price, $group['amount']),
                        'unit' => 'hour',
                        'unit_price' => $price,
                        'discount_pct' => '0',
                    ], ['origin' => 'hours', 'project_id' => (int) $projectId, 'hour_bank_id' => null, 'minutes' => $group['minutes'], 'entries' => $group['entry_ids']]);
                }
            }
        }

        // Precios cerrados: un importe (como mucho lo pendiente).
        $this->fixed($selection['fixed'] ?? [], $projects);

        // Fees: un «Fee de [mes]» por mes.
        $this->fees($selection['fees'] ?? [], $projects, $services);

        // Bolsas vendidas.
        $banks = $this->banks($selection['banks'] ?? [], $projects);
        foreach ($banks as $bank) {
            /** @var Project $project */
            $project = $projects->get($bank->project_id);
            $this->add($bank->project_id, [
                ...WorkLines::bank($bank),
                'service_id' => $services[BillingService::HourBanks->value] ?? null,
                'description' => UnbilledWork::bankDescription($bank, $project),
            ], ['origin' => 'hour_bank', 'project_id' => $bank->project_id, 'hour_bank_id' => $bank->id, 'minutes' => null, 'entries' => []]);
        }

        // Excesos: con la bolsa siguiente si también se factura ahora (D-433).
        $this->overage($selection['overage_entries'] ?? [], $client, $projects, array_values(array_map('intval', $banks->modelKeys())), $services);

        $byProject = $this->items;
        if ($byProject === []) {
            throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.empty')]);
        }

        return DB::transaction(function () use ($client, $byProject, $projects, $by): SalesDocument {
            $lines = [];
            $metas = [];
            $titled = count($byProject) > 1;
            foreach ($byProject as $projectId => $items) {
                if ($titled) {
                    /** @var Project $project */
                    $project = $projects->get($projectId);
                    $lines[] = ['kind' => 'text', 'description' => $project->code.' · '.$project->name];
                    $metas[] = null;
                }
                foreach ($items as $item) {
                    $lines[] = $item['line'];
                    $metas[] = $item['meta'];
                }
            }

            $document = $this->writer->save(null, [
                'client_id' => $client->id,
                'issue_date' => LocalTime::today()->toDateString(),
                'payment_method_id' => PaymentMethod::query()->active()->where('is_default', true)->value('id'),
                'lines' => $lines,
            ], $by);

            $created = SalesDocumentLine::query()->where('sales_document_id', $document->id)->orderBy('position')->orderBy('id')->get();
            $links = [];
            foreach ($created as $index => $line) {
                $meta = $metas[$index] ?? null;
                if ($meta === null) {
                    continue;
                }
                $line->forceFill([
                    'origin' => $meta['origin'],
                    'project_id' => $meta['project_id'],
                    'hour_bank_id' => $meta['hour_bank_id'],
                    'minutes' => $meta['minutes'],
                    'period_start' => $meta['period_start'] ?? null,
                ])->save();

                foreach ($meta['entries'] as $entryId) {
                    try {
                        SalesDocumentTimeEntry::query()->create(['sales_document_line_id' => $line->id, 'time_entry_id' => $entryId]);
                    } catch (UniqueConstraintViolationException) {
                        throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.taken')]);
                    }
                }

                $bankLink = $meta['link_bank_id'] ?? $meta['hour_bank_id'];
                $links[$meta['project_id'].':'.($bankLink ?? 0)] = [$meta['project_id'], $bankLink];
            }

            foreach ($links as [$projectId, $bankId]) {
                SalesDocumentLink::query()->create(['sales_document_id' => $document->id, 'project_id' => $projectId, 'hour_bank_id' => $bankId, 'method' => 'work', 'created_by' => $by->id]);
            }

            DraftWriter::refreshTotals($document);

            activity('invoicing')
                ->causedBy($by)
                ->performedOn($document)
                ->event('created_from_work')
                ->withProperties(['lines' => count($lines), 'entries' => array_sum(array_map(fn (?array $meta): int => $meta === null ? 0 : count($meta['entries']), $metas))])
                ->log('invoicing.created_from_work');

            return $document->refresh();
        });
    }

    /**
     * @param  WorkItem  $line
     * @param  WorkMeta  $meta
     */
    private function add(int $projectId, array $line, array $meta): void
    {
        $this->items[$projectId][] = [
            'line' => [
                'kind' => 'item',
                'service_id' => $line['service_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'discount_pct' => $line['discount_pct'],
                'tax_rate_id' => $this->tax,
            ],
            'meta' => $meta,
        ];
    }

    /**
     * Entradas de horas o de exceso que se pueden facturar: del cliente, aprobadas, facturables y en
     * ninguna factura. Si alguna ya no lo está, error (no se crea nada a medias).
     *
     * @param  list<int>  $ids
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     * @return \Illuminate\Database\Eloquent\Collection<int, TimeEntry>
     */
    private function entries(array $ids, \Illuminate\Database\Eloquent\Collection $projects, bool $overage): \Illuminate\Database\Eloquent\Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $allowed = $overage
            ? $projects->keys()->all()
            : $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::TimeAndMaterials)->keys()->all();

        $entries = UnbilledWork::notInAnyDocument(TimeEntry::query()->whereKey($ids)
            ->whereIn('time_entries.project_id', $allowed)
            ->where('time_entries.status', TimeEntryStatus::Approved->value)
            ->when($overage, fn (Builder $q) => $q->whereNotNull('time_entries.hour_bank_id')->where('time_entries.overage_minutes', '>', 0),
                fn (Builder $q) => $q->where('time_entries.is_billable', true)))
            ->with(['user:id,name', 'task:id,title,task_type_id', 'task.type:id,name'])
            ->orderBy('time_entries.date')->orderBy('time_entries.id')
            ->get();

        if ($entries->count() !== count($ids)) {
            throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.taken')]);
        }

        return $entries;
    }

    /**
     * @param  list<int>  $ids
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     * @return \Illuminate\Database\Eloquent\Collection<int, HourBank>
     */
    private function banks(array $ids, \Illuminate\Database\Eloquent\Collection $projects): \Illuminate\Database\Eloquent\Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $banks = HourBank::query()->whereKey($ids)->whereIn('project_id', $projects->keys()->all())
            ->whereNotNull('price_amount')->where('price_amount', '>', 0)
            ->whereNotExists(fn ($links) => $links->selectRaw('1')->from('billing_document_links')
                ->join('billing_documents', 'billing_documents.id', '=', 'billing_document_links.document_id')
                ->whereColumn('billing_document_links.hour_bank_id', 'hour_banks.id')
                ->where('billing_documents.collection_status', '!=', CollectionStatus::Cancelled->value))
            ->orderBy('start_date')->orderBy('id')->get();

        if ($banks->count() !== count($ids)) {
            throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.bank_taken')]);
        }

        return $banks;
    }

    /**
     * @param  list<int>  $ids
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     * @param  list<int>  $billedBanks  Bolsas que se facturan en esta misma factura
     * @param  array<string, int>  $services
     */
    private function overage(array $ids, Client $client, \Illuminate\Database\Eloquent\Collection $projects, array $billedBanks, array $services): void
    {
        $entries = $this->entries($ids, $projects, true);
        if ($entries->isEmpty()) {
            return;
        }

        $valuation = EntryValuation::for(TimeEntry::query()->whereKey($entries->modelKeys()));
        $banks = HourBank::query()->withTrashed()->whereIn('id', $entries->pluck('hour_bank_id')->unique()->values()->all())->with('renewal:id,renewed_from_id')->get()->keyBy('id');

        foreach ($entries->groupBy('hour_bank_id') as $bankId => $own) {
            /** @var HourBank $bank */
            $bank = $banks->get($bankId);
            /** @var Project $project */
            $project = $projects->get($bank->project_id);
            $fallback = $this->revenue->rate($bank, $project, $client, null);
            $next = $bank->renewal !== null && in_array($bank->renewal->id, $billedBanks, true) ? $bank->renewal->id : null;

            $buckets = [];
            foreach ($own as $entry) {
                $rate = $valuation->value($entry)['rate'] ?? $fallback;
                $key = $rate === null ? '' : Money::round($rate);
                $buckets[$key] ??= ['minutes' => 0, 'ids' => []];
                $buckets[$key]['minutes'] += $entry->overage_minutes;
                $buckets[$key]['ids'][] = $entry->id;
            }

            foreach ($buckets as $rate => $bucket) {
                $price = $rate === '' ? '0' : (string) $rate;
                $amount = Money::round(Money::forMinutes($bucket['minutes'], $price));
                $this->add($bank->project_id, [
                    'service_id' => $services[BillingService::HourBanks->value] ?? null,
                    'description' => __('invoicing_work.work.overage_line', ['bank' => $bank->name, 'period' => UnbilledWork::period(
                        $own->whereIn('id', $bucket['ids'])->min(fn (TimeEntry $entry): string => $entry->date->toDateString()),
                        $own->whereIn('id', $bucket['ids'])->max(fn (TimeEntry $entry): string => $entry->date->toDateString()),
                    )]),
                    'quantity' => WorkLines::quantityFor($bucket['minutes'], $price, $amount),
                    'unit' => 'hour',
                    'unit_price' => $price,
                    'discount_pct' => '0',
                ], ['origin' => 'overage', 'project_id' => $bank->project_id, 'hour_bank_id' => $bank->id, 'link_bank_id' => $next ?? $bank->id, 'minutes' => $bucket['minutes'], 'entries' => $bucket['ids']]);
            }
        }
    }

    /**
     * @param  list<array{project_id: int, month: string}>  $fees
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     * @param  array<string, int>  $services
     */
    private function fees(array $fees, \Illuminate\Database\Eloquent\Collection $projects, array $services): void
    {
        $wanted = (new Collection($fees))->map(fn (array $fee): array => ['project_id' => (int) $fee['project_id'], 'month' => (string) $fee['month']])
            ->unique(fn (array $fee): string => $fee['project_id'].':'.$fee['month'])->values();
        if ($wanted->isEmpty()) {
            return;
        }

        $months = $wanted->map(fn (array $fee): CarbonImmutable => CarbonImmutable::parse($fee['month'].'-01'));
        $from = $months->min() ?? LocalTime::today();
        $to = $months->max() ?? LocalTime::today();
        $fees = $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::MonthlyFee && $project->monthly_fee_amount !== null
            && $wanted->contains('project_id', $project->id))->values();
        $missing = UnbilledReport::missingFeeMonths($fees, $from->startOfMonth(), $to->endOfMonth()->min(LocalTime::today()));

        foreach ($wanted as $fee) {
            /** @var Project|null $project */
            $project = $fees->firstWhere('id', $fee['project_id']);
            $month = CarbonImmutable::parse($fee['month'].'-01');
            $open = $project !== null && (new Collection($missing[$project->id] ?? []))->contains(fn (CarbonImmutable $one): bool => $one->format('Y-m') === $fee['month']);
            if ($project === null || ! $open) {
                throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.fee_taken')]);
            }

            $this->add($project->id, [
                'service_id' => $services[BillingService::Fees->value] ?? null,
                'description' => UnbilledWork::feeDescription($project, $month),
                'quantity' => '1',
                'unit' => 'month',
                'unit_price' => Money::round((string) $project->monthly_fee_amount),
                'discount_pct' => '0',
            ], ['origin' => 'fee', 'project_id' => $project->id, 'hour_bank_id' => null, 'minutes' => null, 'period_start' => $month->toDateString(), 'entries' => []]);
        }
    }

    /**
     * @param  list<array{project_id: int, amount: string, description?: string|null}>  $items
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     */
    private function fixed(array $items, \Illuminate\Database\Eloquent\Collection $projects): void
    {
        if ($items === []) {
            return;
        }

        $fixed = $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::FixedPrice && $project->fixed_price_amount !== null);
        $invoiced = app(UnbilledReport::class)->fixedInvoiced(array_values(array_map('intval', $fixed->keys()->all())));

        foreach ($items as $item) {
            /** @var Project|null $project */
            $project = $fixed->get((int) $item['project_id']);
            $amount = Money::round(str_replace(',', '.', (string) $item['amount']));
            $pending = $project === null ? '0' : Money::round(Money::sub((string) $project->fixed_price_amount, $invoiced[$project->id] ?? '0'));
            if ($project === null || bccomp($amount, '0', 2) <= 0 || bccomp($amount, $pending, 2) > 0) {
                throw ValidationException::withMessages(['selection' => __('invoicing_work.work.errors.fixed_amount', ['pending' => number_format((float) $pending, 2, ',', '.')])]);
            }

            $description = trim((string) ($item['description'] ?? ''));
            $this->add($project->id, [
                'service_id' => null,
                'description' => $description !== '' ? $description : __('invoicing_work.work.fixed_line', ['project' => $project->name]),
                'quantity' => '1',
                'unit' => 'unit',
                'unit_price' => $amount,
                'discount_pct' => '0',
            ], ['origin' => 'fixed', 'project_id' => $project->id, 'hour_bank_id' => null, 'minutes' => null, 'entries' => []]);
        }
    }

    /** El impuesto de las líneas según el régimen del cliente (UE, fuera de la UE, exento o el de por defecto). */
    private static function tax(Client $client): ?int
    {
        $key = match ($client->billingProfile->tax_regime ?? TaxRegime::General) {
            TaxRegime::IntraEu => 'intra_eu',
            TaxRegime::Export => 'export',
            TaxRegime::Exempt => 'exempt',
            default => null,
        };
        $rates = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value);

        return ($key !== null ? (clone $rates)->where('key', $key)->value('id') : null)
            ?? (clone $rates)->where('is_default', true)->value('id')
            ?? CatalogService::query()->whereNotNull('tax_rate_id')->value('tax_rate_id');
    }
}
