<?php

namespace App\Http\Requests\Forecast;

use App\Domain\Projects\ProjectColors;
use App\Enums\ForecastConfidence;
use App\Models\Allocation;
use App\Models\ForecastProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un proyecto previsto (D-281). La forma de los datos; las reglas de negocio
 * (cliente o nombre libre, confirmado siempre «seguro», importe solo con view-financials) las
 * comprueba ForecastProjectWriter. Los permisos, ForecastProjectPolicy (en el controlador).
 */
class ForecastProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:'.ForecastProject::NAME_MAX],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->withoutTrashed()],
            'prospect_name' => ['nullable', 'string', 'max:'.ForecastProject::NAME_MAX, 'required_without:client_id'],
            'color' => ['nullable', 'string', Rule::in(ProjectColors::PALETTE)],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'confidence' => ['nullable', Rule::enum(ForecastConfidence::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:'.Allocation::MINUTES_MAX],
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prospect_name.required_without' => __('forecast.errors.client_or_prospect'),
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

    /**
     * Los datos que llegaron (sin la seguridad si no llegó: se queda la que había).
     *
     * @return array<string, mixed>
     */
    public function forecastData(): array
    {
        $data = $this->validated();

        if (($data['confidence'] ?? null) === null) {
            unset($data['confidence']);
        }

        if (($data['color'] ?? null) === null) {
            unset($data['color']);
        }

        if (($data['owner_user_id'] ?? null) === null) {
            unset($data['owner_user_id']);
        }

        return $data;
    }
}
