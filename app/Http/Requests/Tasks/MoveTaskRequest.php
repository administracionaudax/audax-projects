<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover una tarea a otro proyecto, con la bolsa de destino (SPEC §6). Autoriza el controlador:
 * editar la tarea y crear tareas en el proyecto destino.
 */
class MoveTaskRequest extends FormRequest
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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'hour_bank_id' => ['nullable', 'integer'],
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
