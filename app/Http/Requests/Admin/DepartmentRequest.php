<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Palette;
use App\Enums\Role;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Departamento (SPEC §4.1, D-024): nombre único, color de la paleta de marca y varios responsables,
 * que tienen que ser personas internas activas con rol de responsable o de admin.
 */
class DepartmentRequest extends FormRequest
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
        $department = $this->route('department');
        $current = $department instanceof Department ? $department : null;

        return [
            'name' => ['required', 'string', 'max:100', $this->uniqueName(Department::class, $current?->id, __('admin.departments.errors.name_taken'))],
            'color' => ['required', 'string', Rule::in(Palette::allowed($current?->color))],
            'manager_ids' => ['sometimes', 'array', 'max:50'],
            'manager_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = $this->managerIds();

                if ($validator->errors()->isNotEmpty() || $ids === []) {
                    return;
                }

                $eligible = self::eligibleManagers()->whereKey($ids)->count();

                if ($eligible !== count($ids)) {
                    $validator->errors()->add('manager_ids', __('admin.departments.errors.managers'));
                }
            },
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
            'manager_ids' => __('admin.attributes.managers'),
        ];
    }

    /**
     * @return list<int>
     */
    public function managerIds(): array
    {
        $ids = $this->input('manager_ids', []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric')))) : [];
    }

    /**
     * Personas que pueden ser responsables: internas, activas y con rol de responsable o admin.
     *
     * @return Builder<User>
     */
    public static function eligibleManagers(): Builder
    {
        return User::query()
            ->active()
            ->role([Role::DepartmentManager->value, Role::Admin->value]);
    }
}
