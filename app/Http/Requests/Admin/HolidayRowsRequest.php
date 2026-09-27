<?php

namespace App\Http\Requests\Admin;

use App\Domain\Absences\HolidayImporter;
use App\Domain\Absences\SpanishNationalHolidays;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Confirmar la importación: los festivos de la vista previa que se añaden (fecha y nombre). Se
 * vuelven a validar aquí y HolidayImporter::store se salta las fechas que ya tengan festivo.
 */
class HolidayRowsRequest extends FormRequest
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
            'rows' => ['required', 'array', 'min:1', 'max:'.HolidayImporter::MAX_ROWS],
            'rows.*.date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.SpanishNationalHolidays::MIN_YEAR.'-01-01',
                'before_or_equal:'.SpanishNationalHolidays::MAX_YEAR.'-12-31',
            ],
            'rows.*.name' => ['required', 'string', 'max:'.HolidayImporter::MAX_NAME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows' => (string) __('absences.attributes.rows'),
            'rows.*.date' => (string) __('absences.attributes.date'),
            'rows.*.name' => (string) __('absences.attributes.name'),
        ];
    }

    /**
     * @return list<array{date: string, name: string}>
     */
    public function holidayRows(): array
    {
        /** @var list<array{date: string, name: string}> $rows */
        $rows = $this->validated('rows');

        return array_map(fn (array $row): array => [
            'date' => $row['date'],
            'name' => trim((string) preg_replace('/\s+/u', ' ', $row['name'])),
        ], $rows);
    }
}
