<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Projects\Concerns\ProjectRules;
use App\Http\Requests\Projects\Rules\ActiveInternalUser;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de proyecto (D-022: admins y responsables). El gestor principal es por defecto quien lo
 * crea (D-032) y los miembros iniciales son personas internas activas.
 */
class StoreProjectRequest extends FormRequest
{
    use ProjectRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareProjectInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->projectRules(null),
            'owner_user_id' => ['nullable', 'integer', new ActiveInternalUser],
            'member_ids' => ['nullable', 'array', 'max:100'],
            'member_ids.*' => ['integer', 'distinct', new ActiveInternalUser],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkCodeIsFree($validator, null)];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->projectMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->projectAttributes();
    }

    /**
     * Miembros iniciales (sin repetir).
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->validated('member_ids') ?? [];

        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
