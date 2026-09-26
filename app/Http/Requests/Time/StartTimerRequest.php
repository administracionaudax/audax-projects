<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /temporizador {task_id, description?}. Las reglas de la tarea (miembro, bolsa, semana…)
 * las aplica TimerService con TimeEntryRules.
 */
class StartTimerRequest extends TimeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
