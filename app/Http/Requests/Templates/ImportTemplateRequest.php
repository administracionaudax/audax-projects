<?php

namespace App\Http\Requests\Templates;

use App\Models\ProjectTemplate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Importar una plantilla desde un fichero JSON exportado (D-058). Solo un admin. El contenido lo
 * valida TemplateTransfer::parse() igual que el editor.
 */
class ImportTemplateRequest extends FormRequest
{
    /** Tamaño máximo del fichero, en KB (500 tareas caben de sobra). */
    public const int MAX_KB = 2048;

    public function authorize(): bool
    {
        return $this->user()?->can('create', ProjectTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'extensions:json', 'mimetypes:application/json,text/plain'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $line = __('templates.errors.import_json');
        $invalid = is_string($line) ? $line : 'templates.errors.import_json';

        return [
            'file.extensions' => $invalid,
            'file.mimetypes' => $invalid,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $line = __('templates.attributes.file');

        return ['file' => is_string($line) ? $line : 'file'];
    }
}
