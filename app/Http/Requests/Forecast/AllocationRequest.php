<?php

namespace App\Http\Requests\Forecast;

use App\Enums\AllocationMode;
use App\Models\Allocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una asignación (D-282): la forma de los datos. Las reglas de negocio (persona o
 * hueco, persona de plantilla, cantidad según el modo, fechas, contenedor editable) las comprueba
 * AllocationWriter; los permisos, las políticas en el controlador.
 */
class AllocationRequest extends FormRequest
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
            'user_id' => ['nullable', 'integer', 'required_without:department_id', 'prohibits:department_id'],
            'department_id' => ['nullable', 'integer'],
            'mode' => ['required', Rule::enum(AllocationMode::class)],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:'.Allocation::MINUTES_MAX, Rule::requiredIf(fn (): bool => $this->input('mode') !== AllocationMode::Percent->value)],
            'percent' => ['nullable', 'integer', 'min:1', 'max:'.Allocation::PERCENT_MAX, Rule::requiredIf(fn (): bool => $this->input('mode') === AllocationMode::Percent->value)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:'.Allocation::NOTE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required_without' => __('forecast.errors.person_or_gap'),
            'user_id.prohibits' => __('forecast.errors.person_or_gap'),
            'end_date.after_or_equal' => __('forecast.errors.end_before_start'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return __('forecast.attributes');
    }
}
