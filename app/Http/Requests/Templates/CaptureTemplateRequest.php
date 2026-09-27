<?php

namespace App\Http\Requests\Templates;

use App\Models\Project;
use App\Models\ProjectTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Guardar como plantilla» desde los Ajustes de un proyecto (D-058): quien lo gestiona le da
 * nombre y descripción. Se guardan títulos, tipos, estimaciones, fechas relativas y dependencias;
 * nunca personas, horas ni estados.
 */
class CaptureTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('capture', [ProjectTemplate::class, $this->project()]) ?? false;
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->route('project');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && ! $this->project()->tasks()->exists()) {
                $line = __('templates.errors.capture_empty');
                $validator->errors()->add('capture', is_string($line) ? $line : 'templates.errors.capture_empty');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach (['name', 'description'] as $field) {
            $line = __("templates.attributes.{$field}");
            $attributes[$field] = is_string($line) ? $line : $field;
        }

        return $attributes;
    }
}
