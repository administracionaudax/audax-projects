<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Alta (invitación) y edición de usuarios internos (SPEC §14). El rol «client» no se asigna aquí:
 * los usuarios del portal llegan en la Fase 5. Los datos económicos (coste/hora y tarifa) solo se
 * validan y guardan si quien envía tiene view-financials; si no, se ignoran.
 */
class UserRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name']);

        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }

        $this->normalizeDecimals(['hourly_cost', 'default_hourly_rate']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $ignore = $user instanceof User ? $user->id : null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($ignore)],
            'role' => ['required', 'string', Rule::in(self::assignableRoles())],
            'department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')->withoutTrashed()],
        ];

        if ($this->canSeeFinancials()) {
            $rules['hourly_cost'] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'];
            $rules['default_hourly_rate'] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999.99'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('admin.users.errors.email_taken'),
            'role.in' => __('admin.users.errors.role'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('admin.attributes.name'),
            'email' => __('admin.attributes.email'),
            'role' => __('admin.attributes.role'),
            'department_id' => __('admin.attributes.department'),
            'hourly_cost' => __('admin.attributes.hourly_cost'),
            'default_hourly_rate' => __('admin.attributes.default_hourly_rate'),
        ];
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }

    public function canSeeFinancials(): bool
    {
        return Gate::allows('view-financials');
    }

    /**
     * Datos del usuario (sin el rol), con los económicos solo si puede verlos.
     *
     * @return array{name: string, email: string, department_id: int|null, hourly_cost?: string|null, default_hourly_rate?: string|null}
     */
    public function userData(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'department_id' => $this->filled('department_id') ? $this->integer('department_id') : null,
        ];

        if ($this->canSeeFinancials()) {
            $data['hourly_cost'] = $this->filled('hourly_cost') ? $this->string('hourly_cost')->toString() : null;
            $data['default_hourly_rate'] = $this->filled('default_hourly_rate') ? $this->string('default_hourly_rate')->toString() : null;
        }

        return $data;
    }

    /**
     * Roles que se asignan desde aquí (todos los internos).
     *
     * @return list<string>
     */
    public static function assignableRoles(): array
    {
        return [Role::Admin->value, Role::DepartmentManager->value, Role::Employee->value];
    }
}
