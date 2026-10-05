<?php

namespace App\Http\Requests\Weeklies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * «Unirme a proyectos» (F-034, D-156): varios a la vez, como miembro (nunca como gestor).
 */
final class JoinProjectsRequest extends FormRequest
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
            'project_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'project_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_ids.required' => __('weeklies.validation.projects'),
            'project_ids.min' => __('weeklies.validation.projects'),
            'project_ids.max' => __('weeklies.validation.projects'),
        ];
    }

    /**
     * @return list<int>
     */
    public function projectIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('project_ids', [])));
    }
}
