<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Reorderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Subir o bajar un elemento de un catálogo ordenado (tipos de tarea y estados).
 */
class MoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::in([Reorderer::UP, Reorderer::DOWN])],
        ];
    }

    public function direction(): string
    {
        return $this->string('direction')->toString();
    }
}
