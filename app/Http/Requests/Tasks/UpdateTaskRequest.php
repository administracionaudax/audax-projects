<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editar campos de una tarea desde el panel (cambios parciales: solo los campos enviados).
 * Autoriza el controlador (TaskPolicy::update).
 */
class UpdateTaskRequest extends FormRequest
{
    use TaskFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->taskFieldRules(partial: true);
    }
}
