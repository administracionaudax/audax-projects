<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Soltar una tarjeta en el kanban: estado de destino y vecina (antes de before_id o después de
 * after_id; sin ninguna, al final de la columna). Autoriza el controlador (TaskPolicy::update).
 */
class PositionTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer', 'exists:task_statuses,id'],
            'before_id' => ['nullable', 'integer'],
            'after_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return (array) __('tasks.attributes');
    }
}
