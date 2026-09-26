<?php

namespace App\Http\Requests\HourBanks\Concerns;

use App\Enums\OveragePolicy;
use App\Http\Requests\Projects\Concerns\TranslatesAttributes;
use App\Support\Duration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de los datos de una bolsa (SPEC §4.2 y §8): alta, edición y renovación.
 * La tarifa y el precio solo los fija quien tiene view-financials (plan de la Fase 1); sin el
 * permiso se ignoran. `invoice_reference` no es un dato económico.
 */
trait HourBankRules
{
    use TranslatesAttributes;

    /** Máximo del total de una bolsa: 9999:59 (el mayor valor que admite el campo de duración). */
    public const int MAX_TOTAL_MINUTES = 9999 * 60 + 59;

    /**
     * @return array<string, mixed>
     */
    protected function hourBankRules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->withoutTrashed()],
            'total_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MINUTES],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'overage_policy' => ['required', Rule::enum(OveragePolicy::class)],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($this->canSetFinancials()) {
            $rules['hourly_rate'] = ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
            $rules['price_amount'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function hourBankMessages(): array
    {
        return [
            'end_date.after_or_equal' => $this->transText('hour_banks.errors.end_before_start'),
            'total_minutes.min' => $this->transText('hour_banks.errors.total_range', ['max' => Duration::format(self::MAX_TOTAL_MINUTES)]),
            'total_minutes.max' => $this->transText('hour_banks.errors.total_range', ['max' => Duration::format(self::MAX_TOTAL_MINUTES)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function hourBankAttributes(): array
    {
        return $this->translatedAttributes('hour_banks', [
            'name', 'department_id', 'total_minutes', 'start_date', 'end_date', 'overage_policy', 'hourly_rate',
            'price_amount', 'invoice_reference', 'notes', 'move_open_tasks',
        ]);
    }

    protected function canSetFinancials(): bool
    {
        $user = $this->user();

        return $user !== null && Gate::forUser($user)->allows('view-financials');
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function transText(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
