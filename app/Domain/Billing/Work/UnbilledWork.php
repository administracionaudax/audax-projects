<?php

namespace App\Domain\Billing\Work;

use App\Domain\Billing\UnbilledReport;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\Money;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\BillingService;
use App\Enums\BillingType;
use App\Enums\CollectionStatus;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo que se puede facturar de un cliente ahora mismo («Crear borrador» desde Por facturar, la ficha
 * del proyecto o de la bolsa y el Resumen; PLAN-EMISION §3.8 y §6.2; E3), cada cosa con la línea que
 * daría en la factura:
 *
 * - **Horas** de sus proyectos por horas: las aprobadas y facturables del periodo que no están en
 *   ninguna factura propia (tampoco en un borrador) ni bloqueadas, agrupadas como elija quien factura
 *   (por tarea, persona, tipo de tarea, proyecto o una línea por entrada; H-132) y separadas por
 *   tarifa, cada línea con su importe al céntimo (EntryValuation, la tarifa congelada). Aparte, las
 *   pendientes de aprobar (no entran) y las que ya están en un borrador.
 * - **Bolsas vendidas** sin factura (como Por facturar, D-412): su línea «bolsadehoras».
 * - **Excesos** de bolsa (D-433): las horas de exceso aprobadas sin facturar, a su tarifa. El de una
 *   bolsa renovada se ofrece junto a la siguiente («Con la bolsa X»); el de la activa, suelto.
 * - **Fees**: un «Fee de [mes]» por cada mes ya empezado sin facturar.
 * - **Precios cerrados** (D-432): lo pendiente, como un importe o un hito.
 *
 * Con un proyecto o una bolsa (desde su ficha), solo lo suyo (y, de una bolsa, el exceso de la
 * anterior). Todo en un número fijo de consultas (presupuesto en tests/Feature/Performance).
 *
 * @phpstan-type HoursLine array{key: string, label: string, description: string, minutes: int, rate: string|null, amount: string, entry_ids: list<int>, service_id: int|null}
 */
final class UnbilledWork
{
    public const array GROUPINGS = ['task', 'person', 'type', 'project', 'entry'];

    public function __construct(
        private readonly RevenueCalculator $revenue,
        private readonly UnbilledReport $report,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client, CarbonImmutable $from, CarbonImmutable $to, string $grouping = 'task', ?int $projectId = null, ?int $bankId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $grouping = in_array($grouping, self::GROUPINGS, true) ? $grouping : 'task';
        $bank = $bankId !== null ? HourBank::query()->withTrashed()->find($bankId) : null;
        $projectId ??= $bank?->project_id;

        $projects = Project::query()->withTrashed()->where('client_id', $client->id)
            ->when($projectId !== null, fn (Builder $q) => $q->whereKey($projectId))
            ->orderBy('code')->orderBy('id')
            ->get(['id', 'client_id', 'code', 'name', 'billing_type', 'status', 'start_date', 'due_date', 'monthly_fee_amount', 'fixed_price_amount', 'hourly_rate', 'deleted_at']);
        $services = self::services();

        return [
            'client' => ['id' => $client->id, 'name' => $client->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'grouping' => $grouping,
            'hours' => $bank === null ? $this->hours($projects, $from, $to, $grouping, $services) : [],
            'banks' => $this->banks($client, $projects, $from, $to, $bank, $services),
            'overage' => $this->overage($client, $projects, $from, $to, $bank),
            'fees' => $bank === null ? $this->fees($projects, $from, $to->min($today)) : [],
            'fixed' => $bank === null ? $this->fixed($projects, $today) : [],
        ];
    }

    /**
     * Las entradas que aún no están en ninguna factura propia (ni en un borrador).
     *
     * @param  Builder<TimeEntry>  $entries
     * @return Builder<TimeEntry>
     */
    public static function notInAnyDocument(Builder $entries): Builder
    {
        return $entries->whereNotExists(fn ($billed) => $billed->selectRaw('1')->from('sales_document_time_entry')
            ->whereColumn('sales_document_time_entry.time_entry_id', 'time_entries.id')
            ->whereNull('sales_document_time_entry.released_at'));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<string, int>  $services
     * @return list<array<string, mixed>>
     */
    private function hours(Collection $projects, CarbonImmutable $from, CarbonImmutable $to, string $grouping, array $services): array
    {
        $hourly = $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::TimeAndMaterials && $project->deleted_at === null)->values();
        if ($hourly->isEmpty()) {
            return [];
        }

        $base = fn (): Builder => TimeEntry::query()->whereIn('time_entries.project_id', $hourly->modelKeys())
            ->where('time_entries.is_billable', true)
            ->whereBetween('time_entries.date', [$from->toDateString(), $to->toDateString()]);

        $approved = fn (): Builder => self::notInAnyDocument($base()->where('time_entries.status', TimeEntryStatus::Approved->value));
        $entries = $approved()->with(['user:id,name', 'task:id,title,task_type_id', 'task.type:id,name'])
            ->orderBy('time_entries.date')->orderBy('time_entries.id')
            ->get(['time_entries.id', 'time_entries.user_id', 'time_entries.task_id', 'time_entries.project_id', 'time_entries.hour_bank_id', 'time_entries.date',
                'time_entries.minutes', 'time_entries.overage_minutes', 'time_entries.description', 'time_entries.is_billable', 'time_entries.hourly_rate_snapshot']);
        $valuation = EntryValuation::for($approved());

        $pending = $base()->toBase()->whereIn('time_entries.status', [TimeEntryStatus::Draft->value, TimeEntryStatus::Submitted->value])
            ->selectRaw('time_entries.project_id as project_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.project_id')->orderBy('time_entries.project_id')->pluck('minutes', 'project_id');

        // Las que ya están en un borrador (no se pueden poner en dos facturas a la vez).
        $inDrafts = $base()->toBase()
            ->join('sales_document_time_entry', 'sales_document_time_entry.time_entry_id', '=', 'time_entries.id')
            ->join('sales_document_lines', 'sales_document_lines.id', '=', 'sales_document_time_entry.sales_document_line_id')
            ->join('sales_documents', 'sales_documents.id', '=', 'sales_document_lines.sales_document_id')
            ->whereNull('sales_document_time_entry.released_at')
            ->where('sales_documents.status', 'draft')
            ->selectRaw('time_entries.project_id as project_id, sales_documents.id as document_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.project_id', 'sales_documents.id')
            ->orderBy('time_entries.project_id')->orderBy('sales_documents.id')
            ->get();

        $result = [];
        foreach ($hourly as $project) {
            $own = $entries->where('project_id', $project->id)->values();
            $drafts = $inDrafts->where('project_id', $project->id)->map(fn (object $row): array => ['id' => (int) $row->document_id, 'minutes' => (int) $row->minutes])->values()->all();
            $waiting = (int) ($pending[$project->id] ?? 0);
            if ($own->isEmpty() && $waiting === 0 && $drafts === []) {
                continue;
            }

            $lines = self::group($own, $grouping, $valuation, $services);
            $result[] = [
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'minutes' => (int) $own->sum('minutes'),
                'amount' => Money::round(Money::add('0', ...array_map(fn (array $line): string => $line['amount'], $lines))),
                'pending_minutes' => $waiting,
                'drafts' => $drafts,
                'lines' => $lines,
            ];
        }

        return $result;
    }

    /**
     * Agrupa las entradas en líneas (y cada grupo, por tarifa).
     *
     * @param  \Illuminate\Support\Collection<int, TimeEntry>  $entries
     * @param  array<string, int>  $services
     * @return list<HoursLine>
     */
    public static function group(\Illuminate\Support\Collection $entries, string $grouping, EntryValuation $valuation, array $services): array
    {
        /** @var array<string, array{label: string, rates: array<string, array{minutes: int, income: string, ids: list<int>, from: string, to: string, types: array<string, int>}>}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            [$key, $label] = self::groupKey($entry, $grouping);
            $value = $valuation->value($entry);
            $rate = $value['rate'] === null ? '' : Money::round($value['rate']);
            $date = $entry->date->toDateString();
            $type = $entry->task?->type->name ?? '';

            $groups[$key] ??= ['label' => $label, 'rates' => []];
            $bucket = $groups[$key]['rates'][$rate] ?? ['minutes' => 0, 'income' => '0', 'ids' => [], 'from' => $date, 'to' => $date, 'types' => []];
            $bucket['minutes'] += $entry->minutes;
            $bucket['income'] = Money::add($bucket['income'], $value['income']);
            $bucket['ids'][] = $entry->id;
            $bucket['from'] = min($bucket['from'], $date);
            $bucket['to'] = max($bucket['to'], $date);
            $bucket['types'][$type] = ($bucket['types'][$type] ?? 0) + $entry->minutes;
            $groups[$key]['rates'][$rate] = $bucket;
        }

        $lines = [];
        foreach ($groups as $key => $group) {
            $split = count($group['rates']) > 1;
            foreach ($group['rates'] as $rate => $bucket) {
                arsort($bucket['types']);
                $service = BillingService::classify((string) array_key_first($bucket['types']));
                $period = $grouping === 'entry' ? null : self::period($bucket['from'], $bucket['to']);
                $label = $group['label'].($split && $rate !== '' ? ' ('.number_format((float) $rate, 2, ',', '.').' €/h)' : '');
                $lines[] = [
                    'key' => $key.'@'.$rate,
                    'label' => $label,
                    'description' => $period === null ? $label : $label.' · '.$period,
                    'minutes' => $bucket['minutes'],
                    'rate' => $rate === '' ? null : $rate,
                    'amount' => Money::round($bucket['income']),
                    'entry_ids' => $bucket['ids'],
                    'service_id' => $service === BillingService::Other ? null : ($services[$service->value] ?? null),
                ];
            }
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function groupKey(TimeEntry $entry, string $grouping): array
    {
        $task = $entry->task->title ?? __('invoicing_work.work.no_task');

        return match ($grouping) {
            'person' => ['user:'.$entry->user_id, $entry->user->name ?? __('invoicing_work.work.no_person')],
            'type' => ['type:'.($entry->task->task_type_id ?? 0), $entry->task?->type->name ?? __('invoicing_work.work.no_type')],
            'project' => ['project:'.$entry->project_id, __('invoicing_work.work.hours_of_project')],
            'entry' => ['entry:'.$entry->id, trim($entry->date->format('d/m/Y').' · '.($entry->user->name ?? '').' · '.$task.($entry->description !== null && trim($entry->description) !== '' ? ': '.trim($entry->description) : ''))],
            default => ['task:'.$entry->task_id, $task],
        };
    }

    /** «septiembre de 2026» o «del 28/09/2026 al 03/10/2026». */
    public static function period(string $from, string $to): string
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        if ($start->format('Y-m') === $end->format('Y-m')) {
            return __('invoicing_work.work.month', ['month' => __('invoicing_work.months.'.$start->month), 'year' => $start->year]);
        }

        return __('invoicing_work.work.range', ['from' => $start->format('d/m/Y'), 'to' => $end->format('d/m/Y')]);
    }

    /**
     * Bolsas con precio sin ninguna factura enlazada (un borrador cuenta como factura, D-412).
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, int>  $services
     * @return list<array<string, mixed>>
     */
    private function banks(Client $client, Collection $projects, CarbonImmutable $from, CarbonImmutable $to, ?HourBank $only, array $services): array
    {
        $banks = HourBank::query()
            ->whereIn('project_id', $projects->filter(fn (Project $project): bool => $project->deleted_at === null)->modelKeys())
            ->whereNotNull('price_amount')->where('price_amount', '>', 0)
            ->when($only !== null, fn (Builder $q) => $q->whereKey($only?->id), fn (Builder $q) => $q->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]))
            ->whereNotExists(fn ($links) => $links->selectRaw('1')->from('billing_document_links')
                ->join('billing_documents', 'billing_documents.id', '=', 'billing_document_links.document_id')
                ->whereColumn('billing_document_links.hour_bank_id', 'hour_banks.id')
                ->where('billing_documents.collection_status', '!=', CollectionStatus::Cancelled->value))
            ->with('renewedFrom:id,name')
            ->orderBy('start_date')->orderBy('id')
            ->get(['id', 'project_id', 'name', 'total_minutes', 'hourly_rate', 'price_amount', 'start_date', 'renewed_from_id']);

        return array_values($banks->map(function (HourBank $bank) use ($projects, $services): array {
            /** @var Project $project */
            $project = $projects->firstWhere('id', $bank->project_id);

            return [
                'bank' => ['id' => $bank->id, 'name' => $bank->name],
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'minutes' => $bank->total_minutes,
                'price' => Money::round((string) $bank->price_amount),
                'start_date' => $bank->start_date->toDateString(),
                'previous' => $bank->renewedFrom === null ? null : ['id' => $bank->renewedFrom->id, 'name' => $bank->renewedFrom->name],
                'line' => [...WorkLines::bank($bank), 'description' => self::bankDescription($bank, $project), 'service_id' => $services[BillingService::HourBanks->value] ?? null],
            ];
        })->all());
    }

    public static function bankDescription(HourBank $bank, Project $project): string
    {
        return __('invoicing_work.work.bank_line', [
            'hours' => rtrim(rtrim(number_format($bank->total_minutes / 60, 2, ',', ''), '0'), ','),
            'month' => __('invoicing_work.months.'.$bank->start_date->month),
            'year' => $bank->start_date->year,
            'project' => $project->name,
        ]);
    }

    /**
     * El exceso aprobado y sin facturar de cada bolsa (D-433): el de una renovada, con la siguiente.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function overage(Client $client, Collection $projects, CarbonImmutable $from, CarbonImmutable $to, ?HourBank $only): array
    {
        $bankIds = $only === null ? null : array_values(array_filter([$only->id, $only->renewed_from_id]));
        $entries = self::notInAnyDocument(TimeEntry::query()
            ->whereIn('time_entries.project_id', $projects->modelKeys())
            ->whereNotNull('time_entries.hour_bank_id')
            ->when($bankIds !== null, fn (Builder $q) => $q->whereIn('time_entries.hour_bank_id', $bankIds))
            ->where('time_entries.status', TimeEntryStatus::Approved->value)
            ->where('time_entries.overage_minutes', '>', 0)
            ->when($only === null, fn (Builder $q) => $q->whereBetween('time_entries.date', [$from->toDateString(), $to->toDateString()])));
        $rows = (clone $entries)->orderBy('time_entries.hour_bank_id')->orderBy('time_entries.date')->orderBy('time_entries.id')
            ->get(['time_entries.id', 'time_entries.user_id', 'time_entries.project_id', 'time_entries.hour_bank_id', 'time_entries.date', 'time_entries.minutes',
                'time_entries.overage_minutes', 'time_entries.is_billable', 'time_entries.hourly_rate_snapshot']);
        if ($rows->isEmpty()) {
            return [];
        }

        $valuation = EntryValuation::for(clone $entries);
        $banks = HourBank::query()->withTrashed()->whereIn('id', $rows->pluck('hour_bank_id')->unique()->values()->all())
            ->with(['renewal:id,name,renewed_from_id'])->orderBy('id')->get(['id', 'project_id', 'name', 'hourly_rate']);

        $result = [];
        foreach ($banks as $bank) {
            /** @var Project $project */
            $project = $projects->firstWhere('id', $bank->project_id);
            $fallback = $this->revenue->rate($bank, $project, $client, null);
            $buckets = [];
            foreach ($rows->where('hour_bank_id', $bank->id) as $entry) {
                $rate = $valuation->value($entry)['rate'] ?? $fallback;
                $key = $rate === null ? '' : Money::round($rate);
                $buckets[$key] ??= ['minutes' => 0, 'ids' => []];
                $buckets[$key]['minutes'] += $entry->overage_minutes;
                $buckets[$key]['ids'][] = $entry->id;
            }

            $lines = [];
            foreach ($buckets as $rate => $bucket) {
                $lines[] = [
                    'rate' => $rate === '' ? null : (string) $rate,
                    'minutes' => $bucket['minutes'],
                    'amount' => $rate === '' ? '0.00' : Money::round(Money::forMinutes($bucket['minutes'], (string) $rate)),
                    'entry_ids' => $bucket['ids'],
                ];
            }

            $result[] = [
                'bank' => ['id' => $bank->id, 'name' => $bank->name],
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'next_bank' => $bank->renewal === null ? null : ['id' => $bank->renewal->id, 'name' => $bank->renewal->name],
                'minutes' => array_sum(array_column($lines, 'minutes')),
                'amount' => Money::round(Money::add('0', ...array_map(fn (array $line): string => $line['amount'], $lines))),
                'lines' => $lines,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function fees(Collection $projects, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lessThan($from)) {
            return [];
        }

        $fees = $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::MonthlyFee && $project->monthly_fee_amount !== null
            && $project->status !== ProjectStatus::Archived && $project->deleted_at === null
            && ($project->start_date === null || $project->start_date->toDateString() <= $to->toDateString())
            && ($project->due_date === null || $project->due_date->toDateString() >= $from->toDateString()))->values();
        $months = UnbilledReport::missingFeeMonths($fees, $from, $to);

        $result = [];
        foreach ($fees as $project) {
            foreach ($months[$project->id] ?? [] as $month) {
                $result[] = [
                    'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                    'month' => $month->format('Y-m'),
                    'amount' => Money::round((string) $project->monthly_fee_amount),
                    'description' => self::feeDescription($project, $month),
                ];
            }
        }

        return $result;
    }

    public static function feeDescription(Project $project, CarbonImmutable $month): string
    {
        return __('invoicing_work.work.fee_line', ['month' => __('invoicing_work.months.'.$month->month), 'year' => $month->year, 'project' => $project->name]);
    }

    /**
     * Precios cerrados con algo pendiente (D-432): el precio menos lo facturado y enlazado.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function fixed(Collection $projects, CarbonImmutable $today): array
    {
        $recent = $today->subDays(UnbilledReport::RECENT_DAYS)->toDateString();
        $fixed = $projects->filter(fn (Project $project): bool => $project->billing_type === BillingType::FixedPrice && $project->fixed_price_amount !== null
            && bccomp(Money::of($project->fixed_price_amount), '0', 2) > 0 && $project->deleted_at === null
            && (in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true)
                || ($project->status === ProjectStatus::Completed && ($project->due_date === null || $project->due_date->toDateString() >= $recent))))->values();
        $invoiced = $this->report->fixedInvoiced(array_values(array_map('intval', $fixed->modelKeys())));

        $result = [];
        foreach ($fixed as $project) {
            $price = Money::round((string) $project->fixed_price_amount);
            $billed = $invoiced[$project->id] ?? '0.00';
            $pending = Money::round(Money::sub($price, $billed));
            if (bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $result[] = [
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'price' => $price,
                'invoiced' => Money::round($billed),
                'pending' => $pending,
                'description' => __('invoicing_work.work.fixed_line', ['project' => $project->name]),
            ];
        }

        return $result;
    }

    /**
     * Un servicio del catálogo por tipo (bolsas, fees, desarrollo, diseño…), el primero activo.
     *
     * @return array<string, int>
     */
    public static function services(): array
    {
        $result = [];
        foreach (CatalogService::query()->active()->orderBy('id')->get(['id', 'code', 'name', 'category']) as $service) {
            $kind = $service->category ?? BillingService::classify($service->name, $service->code);
            $result[$kind->value] ??= $service->id;
        }

        return $result;
    }
}
