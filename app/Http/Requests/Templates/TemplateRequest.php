<?php

namespace App\Http\Requests\Templates;

use App\Models\ProjectTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Crear o editar una plantilla desde el editor de /admin/plantillas (D-058): nombre, descripción,
 * si está activa y su estructura (TemplateStructure). Solo un admin.
 */
class TemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof ProjectTemplate
            ? $this->user()?->can('update', $template) ?? false
            : $this->user()?->can('create', ProjectTemplate::class) ?? false;
    }

    /**
     * Con demasiadas tareas o dependencias, solo las reglas de tamaño (TemplateStructure::rulesFor):
     * se rechaza al momento, sin expandir las reglas de cada tarea. Se calcula después de authorize().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            ...TemplateStructure::rulesFor($this->input('structure')),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => TemplateStructure::check($validator, $this->input('structure'))];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TemplateStructure::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach (['name', 'description', 'is_active'] as $field) {
            $line = __("templates.attributes.{$field}");
            $attributes[$field] = is_string($line) ? $line : $field;
        }

        return [...$attributes, ...TemplateStructure::attributes()];
    }

    /**
     * Datos que se guardan (con la estructura ya normalizada).
     *
     * @return array{name: string, description: string|null, is_active: bool, structure: array<string, mixed>}
     */
    public function templateData(): array
    {
        /** @var array<mixed> $structure */
        $structure = $this->validated('structure');
        $description = $this->validated('description');

        return [
            'name' => trim((string) $this->validated('name')),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'is_active' => $this->boolean('is_active'),
            'structure' => TemplateStructure::clean($structure),
        ];
    }
}
