<?php

namespace App\Http\Requests\Absences;

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceRules;
use App\Enums\AbsenceType;
use App\Models\Absence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Solicitar una ausencia propia (D-049): tipo, fechas, parte del día (partial_minutes) y notas.
 * Fase 11, R3: el tipo del catálogo (`leave_type_id`, en lugar de la categoría `type`) y la franja
 * de las de horas (`start_time` y `end_time`, HH:MM).
 * Aquí se valida la forma; las reglas del negocio (orden de las fechas, un año como mucho, parcial
 * de un día y solapes) las aplica AbsenceRules dentro de AbsenceService.
 */
class StoreAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Absence::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['end_date', 'partial_minutes', 'notes', 'type', 'leave_type_id', 'start_time', 'end_time'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => [Rule::requiredIf(fn (): bool => ! $this->filled('leave_type_id')), 'nullable', 'string', Rule::enum(AbsenceType::class)],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'partial_minutes' => ['nullable', 'integer', 'min:1', 'max:'.AbsenceRules::MAX_PARTIAL_MINUTES],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'partial_minutes.min' => (string) __('absences.errors.partial_range'),
            'partial_minutes.max' => (string) __('absences.errors.partial_range'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return [...(array) __('absences.attributes'), ...(array) __('leave.attributes')];
    }

    public function absenceData(): AbsenceData
    {
        /** @var array{type?: string|null, leave_type_id?: int|string|null, start_date: string, end_date?: string|null, partial_minutes?: int|string|null, start_time?: string|null, end_time?: string|null, notes?: string|null} $validated */
        $validated = $this->safe()->only(['type', 'leave_type_id', 'start_date', 'end_date', 'partial_minutes', 'start_time', 'end_time', 'notes']);

        return AbsenceData::fromInput($validated);
    }
}
