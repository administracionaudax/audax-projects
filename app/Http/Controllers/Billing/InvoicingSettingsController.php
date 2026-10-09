<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\CatalogImporter;
use App\Domain\Billing\Issuing\DocumentTotals;
use App\Domain\Billing\Issuing\InvoiceDocumentSettings;
use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Enums\BillingService;
use App\Enums\SalesDocumentStatus;
use App\Enums\ServiceUnit;
use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use App\Http\Controllers\Controller;
use App\Models\CatalogService;
use App\Models\NumberingCounter;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Ajustes de la emisión propia (PLAN-EMISION §6.2, «Ajustes»; D-419, D-423, D-425 y D-426): series y
 * contadores, impuestos, servicios del catálogo (y su importación desde las líneas de Holded),
 * formas de pago y la plantilla del PDF (pie, texto legal, logo y si la ha validado la gestoría).
 * Se ven en /facturacion/ajustes con use-invoicing; cambiarlos, con manage-billing (en la ruta).
 * Cada cambio queda en la auditoría de ajustes.
 */
class InvoicingSettingsController extends Controller
{
    /**
     * Lo que pinta Ajustes en sus apartados de la emisión (BillingSettingsController::edit).
     *
     * @return array<string, mixed>
     */
    public static function props(User $viewer): array
    {
        $year = LocalTime::today()->year;
        $series = NumberingSeries::query()->orderBy('kind')->orderByDesc('is_default')->orderBy('code')->get();
        $counters = NumberingCounter::query()->whereIn('year', [$year, $year + 1])->get()->groupBy('series_id');
        $issued = SalesDocument::query()->where('status', '!=', SalesDocumentStatus::Draft->value)
            ->selectRaw('series_id, COUNT(*) as count')->groupBy('series_id')->pluck('count', 'series_id');
        $document = InvoiceDocumentSettings::get();

        return [
            'can_manage' => InvoicingAccess::manages($viewer),
            'year' => $year,
            'series' => $series->map(fn (NumberingSeries $one): array => [
                'id' => $one->id,
                'code' => $one->code,
                'name' => $one->name,
                'document_type' => $one->document_type->value,
                'format' => $one->format,
                'kind' => $one->kind->value,
                'refund' => $series->firstWhere('id', $one->refund_series_id)?->code,
                'starts_on' => $one->starts_on?->toDateString(),
                'is_default' => $one->is_default,
                'archived' => $one->archived_at !== null,
                'issued' => (int) ($issued[$one->id] ?? 0),
                'counters' => collect([$year, $year + 1])->map(function (int $counterYear) use ($counters, $one): array {
                    $counter = ($counters->get($one->id) ?? collect())->firstWhere('year', $counterYear);
                    $next = $counter === null ? 1 : max($counter->last_number + 1, $counter->first_number);

                    return ['year' => $counterYear, 'next' => $next, 'next_number' => $one->formatNumber($counterYear, $next), 'used' => $counter !== null && $counter->last_number >= $counter->first_number];
                })->values()->all(),
            ])->values()->all(),
            'taxes' => TaxRate::query()->orderBy('kind')->orderBy('position')->orderBy('id')->get()->map(fn (TaxRate $rate): array => [
                'id' => $rate->id,
                'key' => $rate->key,
                'kind' => $rate->kind->value,
                'name' => $rate->name,
                'rate' => (string) $rate->rate,
                'operation_type' => $rate->operation_type?->value,
                'legal_mention' => $rate->legal_mention,
                'legal_mention_en' => $rate->legal_mention_en,
                'is_default' => $rate->is_default,
                'archived' => $rate->archived_at !== null,
            ])->values()->all(),
            'services' => CatalogService::query()->orderBy('name')->orderBy('id')->get()->map(fn (CatalogService $service): array => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'description' => $service->description,
                'unit' => $service->unit->value,
                'unit_price' => (string) $service->unit_price,
                'tax_rate_id' => $service->tax_rate_id,
                'category' => $service->category?->value,
                'archived' => $service->archived_at !== null,
            ])->values()->all(),
            'payment_methods' => PaymentMethod::query()->orderBy('position')->orderBy('id')->get()->map(fn (PaymentMethod $method): array => [
                'id' => $method->id,
                'name' => $method->name,
                'document_text' => $method->document_text,
                'document_text_en' => $method->document_text_en,
                'iban' => $method->iban,
                'due_days' => $method->due_days,
                'is_default' => $method->is_default,
                'archived' => $method->archived_at !== null,
            ])->values()->all(),
            'document' => [
                'footer' => $document['footer'],
                'legal_text' => $document['legal_text'],
                'validated' => $document['validated'],
                'logo' => $document['logo'] !== null,
            ],
            'operation_types' => TaxOperationType::values(),
            'units' => ServiceUnit::values(),
            'categories' => array_map(fn (BillingService $service): string => $service->value, BillingService::cases()),
        ];
    }

    public function updateSeries(Request $request, NumberingSeries $series): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', 'string', 'max:32', 'regex:'.NumberingSeries::FORMAT_PATTERN],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'is_default' => ['boolean'],
            'archived' => ['boolean'],
        ]);

        $issued = SalesDocument::query()->where('series_id', $series->id)->where('status', '!=', SalesDocumentStatus::Draft->value)->exists();
        if ($issued && $data['format'] !== $series->format) {
            throw ValidationException::withMessages(['format' => __('invoicing.errors.series_in_use')]);
        }

        $old = $series->only(['name', 'format', 'starts_on', 'is_default', 'archived_at']);
        DB::transaction(function () use ($series, $data): void {
            if ((bool) ($data['is_default'] ?? false)) {
                NumberingSeries::query()->where('document_type', $series->document_type->value)->where('kind', $series->kind->value)->whereKeyNot($series->id)->update(['is_default' => false]);
            }
            $series->fill([
                'name' => $data['name'],
                'format' => $data['format'],
                'starts_on' => $data['starts_on'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'archived_at' => (bool) ($data['archived'] ?? false) ? ($series->archived_at ?? now()) : null,
            ])->save();
        });

        return $this->saved($request, 'series', $old, $series->only(['name', 'format', 'starts_on', 'is_default', 'archived_at']));
    }

    /**
     * El primer número de un año (solo antes de emitir la primera de ese año, y solo hacia arriba):
     * para continuar la numeración de Holded en un corte a mitad de año (PLAN-EMISION §7.1, opción B).
     */
    public function updateCounter(Request $request, NumberingSeries $series): RedirectResponse
    {
        $year = LocalTime::today()->year;
        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in([$year, $year + 1])],
            'first_number' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        DB::transaction(function () use ($series, $data): void {
            $counter = NumberingCounter::query()->where('series_id', $series->id)->where('year', $data['year'])->lockForUpdate()->first();
            if ($counter !== null && $counter->last_number >= $counter->first_number) {
                throw ValidationException::withMessages(['first_number' => __('invoicing.errors.counter_used', ['year' => $data['year']])]);
            }
            // El contador solo sube (*trigger*): no se puede volver a un número ya reservado.
            $last = $counter->last_number ?? 0;
            if ((int) $data['first_number'] - 1 < $last) {
                throw ValidationException::withMessages(['first_number' => __('invoicing.errors.counter_down', ['next' => $last + 1])]);
            }

            $counter ??= new NumberingCounter(['series_id' => $series->id, 'year' => $data['year'], 'last_number' => 0]);
            $counter->first_number = (int) $data['first_number'];
            $counter->last_number = (int) $data['first_number'] - 1;
            $counter->save();
        });

        return $this->saved($request, 'counter', [], ['series' => $series->code, 'year' => $data['year'], 'first_number' => (int) $data['first_number']]);
    }

    public function storeTax(Request $request): RedirectResponse
    {
        $data = $this->validateTax($request, null);
        $tax = TaxRate::query()->create([
            ...$data,
            'key' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)),
            'position' => (int) TaxRate::query()->max('position') + 1,
        ]);
        $this->defaultOnly($tax);

        return $this->saved($request, 'tax', [], $tax->only(['name', 'rate', 'operation_type']));
    }

    public function updateTax(Request $request, TaxRate $tax): RedirectResponse
    {
        $data = $this->validateTax($request, $tax);
        $used = SalesDocumentLine::query()->where('tax_rate_id', $tax->id)->exists() || CatalogService::query()->where('tax_rate_id', $tax->id)->exists();
        if ($used && (bccomp(DocumentTotals::num((string) $data['rate']), DocumentTotals::num((string) $tax->rate), 2) !== 0 || ($data['operation_type'] ?? null) !== $tax->operation_type?->value || $data['kind'] !== $tax->kind->value)) {
            throw ValidationException::withMessages(['rate' => __('invoicing.errors.tax_in_use')]);
        }

        $old = $tax->only(['name', 'rate', 'operation_type', 'legal_mention', 'is_default', 'archived_at']);
        $tax->fill($data)->save();
        $this->defaultOnly($tax);

        return $this->saved($request, 'tax', $old, $tax->only(['name', 'rate', 'operation_type', 'legal_mention', 'is_default', 'archived_at']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTax(Request $request, ?TaxRate $tax): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(TaxRateKind::class)],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'between:0,100'],
            'operation_type' => ['nullable', 'required_if:kind,vat', Rule::in(TaxOperationType::values())],
            'legal_mention' => ['nullable', 'string', 'max:500'],
            'legal_mention_en' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'archived' => ['boolean'],
        ]);

        return [
            'kind' => $data['kind'],
            'name' => trim($data['name']),
            'rate' => bcadd(DocumentTotals::num((string) $data['rate']), '0', 2),
            'operation_type' => $data['kind'] === TaxRateKind::Vat->value ? $data['operation_type'] : null,
            'legal_mention' => self::clean($data['legal_mention'] ?? null),
            'legal_mention_en' => self::clean($data['legal_mention_en'] ?? null),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'archived_at' => (bool) ($data['archived'] ?? false) ? ($tax->archived_at ?? now()) : null,
        ];
    }

    private function defaultOnly(TaxRate $tax): void
    {
        if ($tax->is_default) {
            TaxRate::query()->where('kind', $tax->kind->value)->whereKeyNot($tax->id)->update(['is_default' => false]);
        }
    }

    public function storeService(Request $request): RedirectResponse
    {
        $service = CatalogService::query()->create($this->validateService($request, null));

        return $this->saved($request, 'service', [], $service->only(['code', 'name', 'unit_price']));
    }

    public function updateService(Request $request, CatalogService $service): RedirectResponse
    {
        $old = $service->only(['code', 'name', 'unit', 'unit_price', 'tax_rate_id', 'archived_at']);
        $service->fill($this->validateService($request, $service))->save();

        return $this->saved($request, 'service', $old, $service->only(['code', 'name', 'unit', 'unit_price', 'tax_rate_id', 'archived_at']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateService(Request $request, ?CatalogService $service): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('billing_services', 'code')->ignore($service?->id)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', Rule::in(ServiceUnit::values())],
            'unit_price' => ['required', 'numeric', 'between:-9999999,9999999'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('kind', 'vat')],
            'category' => ['nullable', Rule::enum(BillingService::class)],
            'archived' => ['boolean'],
        ]);

        return [
            'code' => ($code = self::clean($data['code'] ?? null)) === null ? null : strtoupper($code),
            'name' => trim($data['name']),
            'description' => self::clean($data['description'] ?? null),
            'unit' => $data['unit'],
            'unit_price' => bcadd(DocumentTotals::num((string) $data['unit_price']), '0', 4),
            'tax_rate_id' => $data['tax_rate_id'] ?? null,
            'category' => $data['category'] ?? BillingService::classify($data['name'], $data['code'] ?? null)->value,
            'archived_at' => (bool) ($data['archived'] ?? false) ? ($service->archived_at ?? now()) : null,
        ];
    }

    public function importServices(Request $request, CatalogImporter $importer): RedirectResponse
    {
        $result = $importer->fromHoldedLines();

        activity('settings')->causedBy($request->user())->event('updated')
            ->withProperties(['attributes' => ['billing_services_imported' => $result]])->log('settings.updated');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.services_imported', $result)]);

        return back();
    }

    public function storePaymentMethod(Request $request): RedirectResponse
    {
        $method = PaymentMethod::query()->create([...$this->validatePaymentMethod($request, null), 'position' => (int) PaymentMethod::query()->max('position') + 1]);
        $this->defaultMethodOnly($method);

        return $this->saved($request, 'payment_method', [], $method->only(['name', 'due_days']));
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $method): RedirectResponse
    {
        $old = $method->only(['name', 'document_text', 'iban', 'due_days', 'is_default', 'archived_at']);
        $method->fill($this->validatePaymentMethod($request, $method))->save();
        $this->defaultMethodOnly($method);

        return $this->saved($request, 'payment_method', $old, $method->only(['name', 'document_text', 'iban', 'due_days', 'is_default', 'archived_at']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePaymentMethod(Request $request, ?PaymentMethod $method): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document_text' => ['nullable', 'string', 'max:500'],
            'document_text_en' => ['nullable', 'string', 'max:500'],
            'iban' => ['nullable', 'string', 'max:42', 'regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{10,38}$/'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_default' => ['boolean'],
            'archived' => ['boolean'],
        ]);

        return [
            'name' => trim($data['name']),
            'document_text' => self::clean($data['document_text'] ?? null),
            'document_text_en' => self::clean($data['document_text_en'] ?? null),
            'iban' => ($iban = self::clean($data['iban'] ?? null)) === null ? null : strtoupper((string) preg_replace('/\s+/', '', $iban)),
            'due_days' => $data['due_days'] ?? null,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'archived_at' => (bool) ($data['archived'] ?? false) ? ($method->archived_at ?? now()) : null,
        ];
    }

    private function defaultMethodOnly(PaymentMethod $method): void
    {
        if ($method->is_default) {
            PaymentMethod::query()->whereKeyNot($method->id)->update(['is_default' => false]);
        }
    }

    public function updateDocument(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'footer' => ['nullable', 'string', 'max:1000'],
            'legal_text' => ['nullable', 'string', 'max:2000'],
            'validated' => ['boolean'],
        ]);

        $old = InvoiceDocumentSettings::get();
        InvoiceDocumentSettings::put([
            ...$old,
            'footer' => self::clean($data['footer'] ?? null),
            'legal_text' => self::clean($data['legal_text'] ?? null),
            'validated' => (bool) ($data['validated'] ?? false),
        ]);

        return $this->saved($request, 'document', ['footer' => $old['footer'], 'legal_text' => $old['legal_text'], 'validated' => $old['validated']], $data);
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=3000,max_height=3000']]);
        $file = $request->file('logo');
        abort_if($file === null || is_array($file), 422);

        $size = getimagesize($file->getRealPath());
        $mime = $file->getMimeType() === 'image/jpeg' ? 'image/jpeg' : 'image/png';
        $path = InvoiceDocumentSettings::LOGO_PATH.'.'.($mime === 'image/jpeg' ? 'jpg' : 'png');
        $disk = Storage::disk((string) config('invoicing.disk'));
        $old = InvoiceDocumentSettings::get();
        if ($old['logo'] !== null) {
            $disk->delete($old['logo']['path']);
        }
        $disk->put($path, (string) file_get_contents($file->getRealPath()));

        // Ancho a 60 mm como mucho, proporcional (el alto lo limita la hoja).
        $width = $size === false ? 200 : $size[0];
        $height = $size === false ? 60 : $size[1];
        InvoiceDocumentSettings::put([...$old, 'logo' => ['path' => $path, 'width' => $width, 'height' => $height, 'mime' => $mime]]);

        return $this->saved($request, 'logo', [], ['logo' => $path]);
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $old = InvoiceDocumentSettings::get();
        if ($old['logo'] !== null) {
            Storage::disk((string) config('invoicing.disk'))->delete($old['logo']['path']);
        }
        InvoiceDocumentSettings::put([...$old, 'logo' => null]);

        return $this->saved($request, 'logo', ['logo' => $old['logo']['path'] ?? null], ['logo' => null]);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function saved(Request $request, string $what, array $old, array $new): RedirectResponse
    {
        activity('settings')->causedBy($request->user())->event('updated')
            ->withProperties(['old' => ["invoicing_{$what}" => $old], 'attributes' => ["invoicing_{$what}" => $new]])->log('settings.updated');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.settings_saved')]);

        return back();
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
