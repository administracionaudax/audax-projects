<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear una tarea o subtarea (creación rápida o completa). Autoriza el controlador (TaskPolicy::create).
 */
class StoreTaskRequest extends FormRequest
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
        return [
            ...$this->taskFieldRules(partial: false),
            'parent_task_id' => ['nullable', 'integer'],
        ];
    }
}
