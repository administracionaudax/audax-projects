<?php

namespace App\Http\Requests\Admin;

use App\Domain\Absences\HolidayImporter;
use App\Domain\Absences\SpanishNationalHolidays;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Fichero de festivos para la vista previa de la importación (D-050): un .ics o un CSV (también
 * .txt) de 256 KB como mucho. Su contenido lo valida HolidayImporter, línea a línea. `year` es el
 * año de la página: en él se toman los eventos del .ics que se repiten cada año.
 */
class HolidayFileRequest extends FormRequest
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
            'file' => ['required', 'file', 'extensions:ics,csv,txt', 'max:'.HolidayImporter::MAX_KILOBYTES],
            'year' => ['nullable', 'integer', 'between:'.SpanishNationalHolidays::MIN_YEAR.','.SpanishNationalHolidays::MAX_YEAR],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => (string) __('absences.attributes.file'),
            'year' => (string) __('absences.attributes.year'),
        ];
    }
}
