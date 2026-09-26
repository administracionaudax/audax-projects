<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Palette;
use App\Domain\Admin\TaskTypeIcons;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Department;
use App\Models\TaskType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tipo de tarea (SPEC §4.3): nombre único, color de la paleta, icono de la lista cerrada,
 * departamento opcional, facturable por defecto y activo.
 */
class TaskTypeRequest extends FormRequest
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
        $type = $this->route('taskType');
        $current = $type instanceof TaskType ? $type : null;

        return [
            'name' => ['required', 'string', 'max:100', $this->uniqueName(TaskType::class, $current?->id, __('admin.task_types.errors.name_taken'))],
            'color' => ['required', 'string', Rule::in(Palette::allowed($current?->color))],
            'icon' => ['nullable', 'string', Rule::in(TaskTypeIcons::ICONS)],
            'department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')->withoutTrashed()],
            'is_billable_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.in' => __('admin.errors.palette'),
            'icon.in' => __('admin.task_types.errors.icon'),
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
            'icon' => __('admin.attributes.icon'),
            'department_id' => __('admin.attributes.department'),
        ];
    }

    /**
     * @return array{name: string, color: string, icon: string|null, department_id: int|null, is_billable_default: bool, is_active: bool}
     */
    public function typeData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'color' => $this->string('color')->toString(),
            'icon' => $this->filled('icon') ? $this->string('icon')->toString() : null,
            'department_id' => $this->filled('department_id') ? $this->integer('department_id') : null,
            'is_billable_default' => $this->boolean('is_billable_default'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
