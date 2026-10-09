<?php

namespace App\Http\Requests\Billing;

use App\Enums\ServiceUnit;
use App\Models\HourBank;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un borrador de factura desde el editor (PLAN-EMISION §6.2; D-428). Quién: use-invoicing (en la
 * ruta). Las líneas, de 1 a 200: de concepto (con importe) o de texto.
 */
class SalesDocumentRequest extends FormRequest
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
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'series_id' => ['nullable', 'integer', Rule::exists('numbering_series', 'id')->where('document_type', 'invoice')->whereNull('archived_at')],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'operation_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'withholding_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('kind', 'withholding')],
            'body' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'customer_reference' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'hour_bank_id' => ['nullable', 'integer', 'exists:hour_banks,id'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.kind' => ['required', 'in:item,text'],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:billing_services,id'],
            'lines.*.description' => ['nullable', 'string', 'max:2000', 'required_if:lines.*.kind,text'],
            'lines.*.quantity' => ['nullable', 'required_if:lines.*.kind,item', 'numeric', 'between:-999999,999999'],
            'lines.*.unit' => ['nullable', Rule::in(ServiceUnit::values())],
            'lines.*.unit_price' => ['nullable', 'required_if:lines.*.kind,item', 'numeric', 'between:-9999999,9999999'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('kind', 'vat')],
            'after' => ['nullable', 'in:emitir'],
        ];
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
    public function draft(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->except('after');
        $data['lines'] = array_values(array_map(fn (array $line): array => [
            ...$line,
            'quantity' => isset($line['quantity']) ? (string) $line['quantity'] : null,
            'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
            'discount_pct' => isset($line['discount_pct']) ? (string) $line['discount_pct'] : null,
        ], (array) $data['lines']));

        return $data;
    }
}
