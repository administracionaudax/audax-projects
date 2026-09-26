<?php

namespace App\Http\Requests\Time;

use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /temporizador/parar {minutes?, task_id?}: sin datos imputa lo medido; con ellos, otra
 * duración u otra tarea (diálogo al parar si la imputación falla, D-035).
 */
class StopTimerRequest extends TimeRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeDuration('minutes');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['nullable', 'integer', 'min:1', 'max:'.TimeEntry::MAX_MINUTES_PER_DAY],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ];
    }
}
