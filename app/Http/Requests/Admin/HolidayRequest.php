<?php

namespace App\Http\Requests\Admin;

use App\Domain\Absences\SpanishNationalHolidays;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Holiday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un festivo (D-050): una fecha que aún no tenga festivo y un nombre.
 */
class HolidayRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.SpanishNationalHolidays::MIN_YEAR.'-01-01',
                'before_or_equal:'.SpanishNationalHolidays::MAX_YEAR.'-12-31',
                Rule::unique('holidays', 'date')->ignore($holiday instanceof Holiday ? $holiday->id : null),
            ],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.unique' => (string) __('absences.holidays.errors.date_taken'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => (string) __('absences.attributes.date'),
            'name' => (string) __('absences.attributes.name'),
        ];
    }
}
