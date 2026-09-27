<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nuevas fechas de una tarea movida o redimensionada (Gantt, calendario o panel) y, al confirmar,
 * si se desplazan también sus sucesoras en conflicto (SPEC §6.1: nunca sin confirmación).
 */
class RescheduleTaskRequest extends FormRequest
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
            'start_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'shift_successors' => ['sometimes', 'boolean'],
        ];
    }

    public function startDate(): ?string
    {
        $value = $this->validated('start_date');

        return is_string($value) ? $value : null;
    }

    public function dueDate(): ?string
    {
        $value = $this->validated('due_date');

        return is_string($value) ? $value : null;
    }
}
