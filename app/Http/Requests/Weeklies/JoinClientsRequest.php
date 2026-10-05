<?php

namespace App\Http\Requests\Weeklies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * «Unirme a clientes» de la Weekly (F-034, D-221): varios a la vez. Es una suscripción de la Weekly,
 * no una membresía de proyecto.
 */
final class JoinClientsRequest extends FormRequest
{
    public const int MAX = 50;

    public function authorize(): bool
    {
        return Gate::allows('use-weeklies');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'client_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_ids.required' => __('weeklies.validation.clients'),
            'client_ids.min' => __('weeklies.validation.clients'),
            'client_ids.max' => __('weeklies.validation.clients'),
        ];
    }

    /**
     * @return list<int>
     */
    public function clientIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('client_ids', [])));
    }
}
