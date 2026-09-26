<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /horas/aprobaciones/aprobar {periods: [id, …]}: aprobar varias semanas a la vez.
 */
class ApproveWeeksRequest extends TimeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'periods' => ['required', 'array', 'min:1', 'max:100'],
            'periods.*' => ['integer', 'distinct', 'exists:timesheet_periods,id'],
        ];
    }

    /**
     * @return list<int>
     */
    public function periodIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = (array) $this->input('periods', []);

        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids));
    }
}
