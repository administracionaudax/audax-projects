<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Http\Requests\Projects\Concerns\ProjectRules;
use App\Http\Requests\Projects\Rules\ActiveInternalUser;
use App\Http\Requests\Templates\Concerns\CreatesFromTemplate;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de proyecto (D-022: admins y responsables). El gestor principal es por defecto quien lo
 * crea (D-032) y los miembros iniciales son personas internas activas. Opcionalmente, desde una
 * plantilla (D-058, CreatesFromTemplate): `template_id`, `template_start` y, si es de bolsas, su
 * primera bolsa (`hour_bank.*`).
 */
class StoreProjectRequest extends FormRequest
{
    use CreatesFromTemplate, HourBankRules, ProjectRules {
        ProjectRules::canSetFinancials insteadof HourBankRules;
        ProjectRules::transText insteadof HourBankRules;
    }

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
            ...$this->templateRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkCodeIsFree($validator, null),
            fn (Validator $validator) => $this->checkTemplate($validator),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->projectMessages(), ...$this->templateMessages()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...$this->projectAttributes(), ...$this->templateAttributes()];
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
