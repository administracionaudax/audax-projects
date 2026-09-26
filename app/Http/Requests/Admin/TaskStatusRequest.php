<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Palette;
use App\Enums\TaskStatusCategory;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Estado de tarea (SPEC §4.3): nombre único, color de la paleta, categoría y si es el de por
 * defecto. Las reglas entre estados (uno por defecto, categorías mínimas) están en TaskStatusCatalog.
 */
class TaskStatusRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name']);

        $color = $this->input('color');
        if (is_string($color)) {
            $this->merge(['color' => strtoupper(trim($color))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $status = $this->route('taskStatus');
        $current = $status instanceof TaskStatus ? $status : null;

        return [
            'name' => ['required', 'string', 'max:60', $this->uniqueName(TaskStatus::class, $current?->id, __('admin.statuses.errors.name_taken'))],
            'color' => ['required', 'string', Rule::in(Palette::allowed($current?->color))],
            'category' => ['required', 'string', Rule::enum(TaskStatusCategory::class)],
            'is_default' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.in' => __('admin.errors.palette'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('admin.attributes.name'),
            'color' => __('admin.attributes.color'),
            'category' => __('admin.attributes.category'),
            'is_default' => __('admin.attributes.is_default'),
        ];
    }

    /**
     * @return array{name: string, color: string, category: string, is_default: bool}
     */
    public function statusData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'color' => $this->string('color')->toString(),
            'category' => $this->string('category')->toString(),
            'is_default' => $this->boolean('is_default'),
        ];
    }
}
