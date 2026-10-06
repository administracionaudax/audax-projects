<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\ForecastProject;

/**
 * Crear el proyecto real desde un previsto (D-286): el alta de proyecto de siempre (mismas reglas,
 * StoreProjectRequest, D-022) más `copy_allocations`. Sin plantilla.
 */
class CreateProjectFromForecastRequest extends StoreProjectRequest
{
    public function authorize(): bool
    {
        $forecast = $this->route('forecast');

        return $forecast instanceof ForecastProject && ($this->user()?->can('createProject', $forecast) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'template_id' => ['prohibited'],
            'copy_allocations' => ['sometimes', 'boolean'],
        ];
    }
}
