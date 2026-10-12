<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedPayload;
use App\Domain\Billing\Issuing\DocumentTotals;
use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Enums\RecurringPeriodicity;
use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use App\Models\CatalogService;
use App\Models\HoldedContact;
use App\Models\PaymentMethod;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importa las recurrentes de Holded **en pausa** (PLAN-EMISION §7.2; E3), para activarlas el día
 * del corte: lee la API v2 en solo lectura (HoldedApi::recurringInvoices) y nunca escribe en Holded.
 *
 * Cada una llega con su cliente casado (el contacto de Holded ya casado con un cliente de Audax),
 * sus líneas (con el servicio del catálogo por su código), su periodicidad y su próxima fecha (el
 * día del mes, el de esa fecha), en borrador (como las genera hoy Holded) y con el proyecto si el
 * cliente tiene un único fee vivo con ese importe. **No inventa nada**: sin cliente casado o sin
 * una periodicidad que se entienda, no se importa y se dice por qué; un servicio o un impuesto que no
 * se reconocen se avisan (la línea va sin servicio o con el IVA por defecto). Las ya importadas (por
 * su id de Holded) no se tocan. Se revisan una a una («Revisada») antes de reanudarlas.
 *
 * @phpstan-type ImportRow array{holded_id: string, name: string, client: string|null, periodicity: string|null, next: string|null, subtotal: string, status: 'created'|'would_create'|'existing'|'failed', problems: list<string>, warnings: list<string>, recurring_id: int|null}
 */
final class HoldedRecurringImporter
{
    public function __construct(private readonly RecurringInvoices $recurrences) {}

    /**
     * @return list<ImportRow>
     */
    public function import(HoldedApi $api, User $by, bool $dryRun = false): array
    {
        $catalog = CatalogService::query()->whereNotNull('code')->get(['id', 'code', 'tax_rate_id'])->keyBy(fn (CatalogService $service): string => strtoupper((string) $service->code));
        $vat = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('operation_type', TaxOperationType::S1->value)->get(['id', 'rate']);
        $defaultTax = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('is_default', true)->value('id');
        $payment = PaymentMethod::query()->active()->where('is_default', true)->value('id');
        $rows = [];

        foreach ($api->recurringInvoices() as $item) {
            $rows[] = $this->one($item, $catalog, $vat, $defaultTax, $payment, $by, $dryRun);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, CatalogService>  $catalog
     * @param  \Illuminate\Database\Eloquent\Collection<int, TaxRate>  $vat
     * @return ImportRow
     */
    private function one(array $item, Collection $catalog, \Illuminate\Database\Eloquent\Collection $vat, mixed $defaultTax, mixed $payment, User $by, bool $dryRun): array
    {
        $id = HoldedPayload::id($item) ?? '';
        $contactId = HoldedPayload::string($item, 'contact_id', 'contactId', 'contact.id');
        $contactName = HoldedPayload::string($item, 'contact_name', 'contactName', 'contact.name') ?? '—';
        $items = HoldedPayload::list($item, 'items', 'products', 'lines');
        $concept = HoldedPayload::string($items[0] ?? [], 'name') ?? '';
        $name = HoldedPayload::string($item, 'name', 'description', 'title')
            ?? (string) __('invoicing_work.import.name', ['contact' => $contactName, 'concept' => $concept]);
        $interval = self::interval($item);
        $next = HoldedPayload::date($item, 'next_date', 'nextDate', 'next_run', 'next_invoice_date', 'next', 'date');
        $problems = [];
        $warnings = [];

        $row = [
            'holded_id' => $id,
            'name' => mb_substr($name, 0, 200),
            'client' => null,
            'periodicity' => $interval === null ? null : RecurringPeriodicity::fromMonths($interval)->label(),
            'next' => $next?->toDateString(),
            'subtotal' => '0.00',
            'status' => 'failed',
            'problems' => [],
            'warnings' => [],
            'recurring_id' => null,
        ];

        $existing = $id === '' ? null : RecurringInvoice::query()->where('holded_recurring_id', $id)->first();
        if ($existing !== null) {
            return [...$row, 'client' => $existing->client->name, 'status' => 'existing', 'recurring_id' => $existing->id];
        }

        $client = $contactId === null ? null : HoldedContact::query()->where('holded_id', $contactId)->whereNotNull('client_id')->with('client')->first()?->client;
        if ($client === null) {
            $problems[] = (string) __('invoicing_work.import.no_client', ['contact' => $contactName]);
        }
        if ($interval === null) {
            $problems[] = (string) __('invoicing_work.import.no_periodicity', ['value' => self::rawInterval($item)]);
        }
        if ($next === null) {
            $problems[] = (string) __('invoicing_work.import.no_next');
        }

        $lines = [];
        $subtotal = '0';
        foreach ($items as $line) {
            $label = HoldedPayload::string($line, 'name') ?? HoldedPayload::string($line, 'description') ?? '—';
            $code = HoldedPayload::string($line, 'code', 'sku', 'product_code', 'service_code');
            $service = $code !== null ? $catalog->get(strtoupper($code)) : null;
            if ($code !== null && $service === null) {
                $warnings[] = (string) __('invoicing_work.import.unknown_service', ['line' => $label, 'code' => $code]);
            }
            $rate = HoldedPayload::taxRate($line);
            $tax = $rate === null ? $defaultTax : $vat->first(fn (TaxRate $one): bool => bccomp(DocumentTotals::num((string) $one->rate), $rate, 2) === 0)?->id;
            if ($tax === null) {
                $warnings[] = (string) __('invoicing_work.import.unknown_tax', ['line' => $label, 'tax' => $rate ?? '—']);
                $tax = $defaultTax;
            }
            $quantity = HoldedPayload::decimal($line, 4, 'units', 'quantity') ?? '1';
            $price = HoldedPayload::decimal($line, 4, 'price', 'unit_price', 'subtotal') ?? '0';
            $discount = HoldedPayload::decimal($line, 2, 'discount', 'discount_pct') ?? '0';
            $description = HoldedPayload::string($line, 'description');
            $lines[] = [
                'kind' => 'item',
                'service_id' => $service?->id,
                'description' => $service !== null ? ($description ?? $label) : trim(implode("\n", array_filter([$label, $description]))),
                'quantity' => $quantity,
                'unit' => 'unit',
                'unit_price' => $price,
                'discount_pct' => $discount,
                'tax_rate_id' => is_int($tax) ? $tax : null,
            ];
            $subtotal = bcadd($subtotal, DocumentTotals::lineBase($quantity, $price, $discount), 2);
        }
        if ($lines === []) {
            $problems[] = (string) __('invoicing_work.import.no_lines');
        }

        $row = [...$row, 'client' => $client?->name, 'subtotal' => bcadd($subtotal, '0', 2), 'warnings' => $warnings];
        if ($client === null || $interval === null || $next === null || $lines === []) {
            return [...$row, 'problems' => $problems];
        }

        if ($dryRun) {
            return [...$row, 'status' => 'would_create'];
        }

        $start = HoldedPayload::date($item, 'start_date', 'startDate', 'start') ?? $next;
        $recurring = DB::transaction(function () use ($id, $item, $name, $client, $interval, $next, $start, $lines, $payment, $subtotal, $by): RecurringInvoice {
            $recurring = $this->recurrences->save(null, [
                'name' => mb_substr($name, 0, 200),
                'client_id' => $client->id,
                'project_id' => self::project($client->id, bcadd($subtotal, '0', 2)),
                'periodicity' => RecurringPeriodicity::fromMonths($interval)->value,
                'interval_months' => $interval,
                'day_of_month' => $next->day,
                // El calendario empieza en la próxima de Holded: la primera que generará Audax.
                'start_date' => $next->toDateString(),
                'end_date' => HoldedPayload::date($item, 'end_date', 'endDate', 'until')?->toDateString(),
                'max_occurrences' => null,
                'mode' => RecurringInvoice::MODE_DRAFT,
                'payment_method_id' => is_int($payment) ? $payment : null,
                'body' => HoldedPayload::string($item, 'notes', 'body'),
                'lines' => $lines,
            ], $by, paused: true);
            $recurring->forceFill([
                'holded_recurring_id' => $id,
                'holded_snapshot' => ['item' => $item, 'start_date' => $start->toDateString()],
            ])->save();

            return $recurring;
        });

        return [...$row, 'status' => 'created', 'recurring_id' => $recurring->id];
    }

    /** El proyecto si el cliente tiene un único fee vivo con ese importe al mes. */
    private static function project(int $clientId, string $subtotal): ?int
    {
        $fees = Project::query()->where('client_id', $clientId)->where('billing_type', BillingType::MonthlyFee->value)
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
            ->get(['id', 'monthly_fee_amount'])
            ->filter(fn (Project $project): bool => $project->monthly_fee_amount !== null && bccomp(DocumentTotals::num((string) $project->monthly_fee_amount), DocumentTotals::num($subtotal), 2) === 0);

        return $fees->count() === 1 ? $fees->first()?->id : null;
    }

    /**
     * Meses entre una y otra: «month» × frecuencia, «quarter», «year»… o null si no se entiende.
     *
     * @param  array<string, mixed>  $item
     */
    public static function interval(array $item): ?int
    {
        $raw = mb_strtolower(trim(self::rawInterval($item)));
        $every = (int) (HoldedPayload::numeric($item['frequency'] ?? $item['every'] ?? $item['interval_count'] ?? $item['repeat_every'] ?? 1) ?? 1);
        $every = max(1, $every);

        $months = match (true) {
            in_array($raw, ['month', 'months', 'monthly', 'mensual', 'mes', 'meses', 'm'], true) => 1,
            in_array($raw, ['bimonthly', 'bimestral'], true) => 2,
            in_array($raw, ['quarter', 'quarters', 'quarterly', 'trimestral', 'trimestre', 'q'], true) => 3,
            in_array($raw, ['semester', 'semiannual', 'half_year', 'semestral', 'semestre'], true) => 6,
            in_array($raw, ['year', 'years', 'yearly', 'annual', 'anual', 'año', 'y'], true) => 12,
            default => null,
        };

        return $months === null || $months * $every > 24 ? null : $months * $every;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function rawInterval(array $item): string
    {
        foreach (['interval', 'periodicity', 'period', 'frequency_unit', 'recurrence', 'repeat', 'frequency'] as $key) {
            if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '' && ! is_numeric($item[$key])) {
                return $item[$key];
            }
        }

        return '—';
    }
}
