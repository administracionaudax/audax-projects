<?php

namespace App\Http\Requests\Billing;

use App\Enums\RecurringPeriodicity;
use App\Enums\ServiceUnit;
use App\Models\HourBank;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una factura recurrente desde su editor (E3). Quién: manage-billing (en la ruta). Fin: nunca, en
 * una fecha o tras un número de veces. Las líneas, como las de una factura (1 a 100).
 */
class RecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'hour_bank_id' => ['nullable', 'integer', 'exists:hour_banks,id'],
            'periodicity' => ['required', Rule::in(RecurringPeriodicity::values())],
            'interval_months' => ['nullable', 'required_if:periodicity,custom', 'integer', 'between:1,24'],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'in:never,date,count'],
            'end_date' => ['nullable', 'required_if:end,date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'max_occurrences' => ['nullable', 'required_if:end,count', 'integer', 'between:1,999'],
            'mode' => ['required', 'in:draft,issue'],
            'auto_send' => ['boolean'],
            'series_id' => ['nullable', 'integer', Rule::exists('numbering_series', 'id')->where('document_type', 'invoice')->whereNull('archived_at')],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'due_days' => ['nullable', 'integer', 'between:0,365'],
            'withholding_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('kind', 'withholding')],
            'body' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'customer_reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.kind' => ['required', 'in:item,text'],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:billing_services,id'],
            'lines.*.description' => ['nullable', 'string', 'max:2000', 'required_if:lines.*.kind,text'],
            'lines.*.quantity' => ['nullable', 'required_if:lines.*.kind,item', 'numeric', 'between:-999999,999999'],
            'lines.*.unit' => ['nullable', Rule::in(ServiceUnit::values())],
            'lines.*.unit_price' => ['nullable', 'required_if:lines.*.kind,item', 'numeric', 'between:-9999999,9999999'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('kind', 'vat')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = (array) __('invoicing_work.recurring.attributes');

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bank = $this->input('hour_bank_id');
            if ($bank !== null && HourBank::query()->whereKey($bank)->value('project_id') !== (int) $this->input('project_id')) {
                $validator->errors()->add('hour_bank_id', __('validation.exists', ['attribute' => 'bolsa']));
            }
            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (is_array($line) && ($line['kind'] ?? null) === 'item' && trim((string) ($line['description'] ?? '')) === '' && ($line['service_id'] ?? null) === null) {
                    $validator->errors()->add("lines.{$index}.description", __('validation.required', ['attribute' => 'concepto']));
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function recurring(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->except('end');
        $end = $this->input('end');
        $data['end_date'] = $end === 'date' ? $data['end_date'] ?? null : null;
        $data['max_occurrences'] = $end === 'count' ? (int) ($data['max_occurrences'] ?? 1) : null;
        $data['auto_send'] = $this->boolean('auto_send');
        $data['lines'] = array_values(array_map(fn (array $line): array => [
            ...$line,
            'quantity' => isset($line['quantity']) ? (string) $line['quantity'] : null,
            'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
            'discount_pct' => isset($line['discount_pct']) ? (string) $line['discount_pct'] : null,
        ], (array) $data['lines']));

        return $data;
    }
}
