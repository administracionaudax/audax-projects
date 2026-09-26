<?php

namespace App\Http\Requests\HourBanks\Concerns;

use App\Enums\OveragePolicy;
use App\Http\Requests\Projects\Concerns\TranslatesAttributes;
use App\Support\Duration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de los datos de una bolsa (SPEC §4.2 y §8): alta, edición, renovación y la primera bolsa
 * al pasar a «bolsa de horas» un proyecto con tareas (con un prefijo, p. ej. `hour_bank.`).
 * - La tarifa y el precio solo los fija quien tiene view-financials (plan de la Fase 1); sin el
 *   permiso se ignoran. `invoice_reference` no es un dato económico.
 * - Departamento: uno que exista; al editar o renovar se puede conservar el de la bolsa aunque
 *   se haya eliminado (como el cliente de un proyecto).
 */
trait HourBankRules
{
    use TranslatesAttributes;

    /** Máximo del total de una bolsa: 9999:59 (el mayor valor que admite el campo de duración). */
    public const int MAX_TOTAL_MINUTES = 9999 * 60 + 59;

    /**
     * @param  string  $prefix  para validar la bolsa dentro de otro formulario (p. ej. `hour_bank.`)
     * @param  int|null  $keepDepartmentId  departamento actual, válido aunque se haya eliminado
     * @return array<string, mixed>
     */
    protected function hourBankRules(string $prefix = '', ?int $keepDepartmentId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'department_id' => [
                'nullable',
                'integer',
                // El validador agrupa la condición entre paréntesis: (deleted_at IS NULL OR id = actual).
                Rule::exists('departments', 'id')->where(fn (Builder $query) => $query->whereNull('deleted_at')
                    ->when($keepDepartmentId !== null, fn (Builder $kept) => $kept->orWhere('id', $keepDepartmentId))),
            ],
            'total_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MINUTES],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$prefix}start_date"],
            'overage_policy' => ['required', Rule::enum(OveragePolicy::class)],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($this->canSetFinancials()) {
            $rules['hourly_rate'] = ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
            $rules['price_amount'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        }

        return $this->prefixed($prefix, $rules);
    }

    /**
     * @return array<string, string>
     */
    protected function hourBankMessages(string $prefix = ''): array
    {
        $range = $this->transText('hour_banks.errors.total_range', ['max' => Duration::format(self::MAX_TOTAL_MINUTES)]);

        return $this->prefixed($prefix, [
            'end_date.after_or_equal' => $this->transText('hour_banks.errors.end_before_start'),
            'total_minutes.min' => $range,
            'total_minutes.max' => $range,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function hourBankAttributes(string $prefix = ''): array
    {
        return $this->prefixed($prefix, $this->translatedAttributes('hour_banks', [
            'name', 'department_id', 'total_minutes', 'start_date', 'end_date', 'overage_policy', 'hourly_rate',
            'price_amount', 'invoice_reference', 'notes', 'move_open_tasks',
        ]));
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $values
     * @return array<string, T>
     */
    private function prefixed(string $prefix, array $values): array
    {
        if ($prefix === '') {
            return $values;
        }

        $result = [];
        foreach ($values as $key => $value) {
            $result[$prefix.$key] = $value;
        }

        return $result;
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
