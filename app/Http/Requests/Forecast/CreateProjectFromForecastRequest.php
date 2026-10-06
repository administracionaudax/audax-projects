<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Client;
use App\Models\ForecastProject;
use Illuminate\Validation\Validator;

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
        $rules = [
            ...parent::rules(),
            'template_id' => ['prohibited'],
            'copy_allocations' => ['sometimes', 'boolean'],
            // Previsto de un cliente nuevo (D-308): se crea el cliente con su nombre libre.
            'create_client' => ['sometimes', 'boolean'],
        ];

        if ($this->boolean('create_client')) {
            $rules['client_id'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if (! $this->boolean('create_client')) {
                    return;
                }

                $forecast = $this->route('forecast');
                $name = $forecast instanceof ForecastProject && $forecast->client_id === null ? trim((string) $forecast->prospect_name) : '';

                if ($name === '') {
                    $validator->errors()->add('create_client', __('forecast.errors.no_prospect'));
                } elseif (! ($this->user()?->can('create', Client::class) ?? false)) {
                    $validator->errors()->add('create_client', __('forecast.errors.client_forbidden'));
                } elseif (Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                    $validator->errors()->add('create_client', __('forecast.errors.client_exists'));
                }
            },
        ];
    }
}
