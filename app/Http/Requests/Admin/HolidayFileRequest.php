<?php

namespace App\Http\Requests\Admin;

use App\Domain\Absences\HolidayImporter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Fichero de festivos para la vista previa de la importación (D-050): un .ics o un CSV (también
 * .txt) de 256 KB como mucho. Su contenido lo valida HolidayImporter, línea a línea.
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => (string) __('absences.attributes.file')];
    }
}
