<?php

namespace App\Http\Requests\Admin;

use App\Domain\Absences\SpanishNationalHolidays;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * «Añadir los festivos nacionales de España de AAAA» (D-050): el año.
 */
class HolidayYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:'.SpanishNationalHolidays::MIN_YEAR.','.SpanishNationalHolidays::MAX_YEAR],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.between' => (string) __('absences.holidays.errors.year_range', ['min' => SpanishNationalHolidays::MIN_YEAR, 'max' => SpanishNationalHolidays::MAX_YEAR]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['year' => (string) __('absences.attributes.year')];
    }
}
