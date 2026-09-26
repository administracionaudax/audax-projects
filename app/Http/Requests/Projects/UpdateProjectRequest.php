<?php

namespace App\Http\Requests\Projects;

use App\Enums\BillingType;
use App\Http\Requests\Projects\Concerns\ProjectRules;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edición de los datos de un proyecto (ajustes). El gestor principal y los miembros se cambian
 * aparte; archivar tiene su botón. Un proyecto con bolsas no deja de ser de bolsas.
 */
class UpdateProjectRequest extends FormRequest
{
    use ProjectRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->project()) ?? false;
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->route('project');
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
        return $this->projectRules($this->project());
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkCodeIsFree($validator, $this->project()),
            function (Validator $validator): void {
                $project = $this->project();

                if ($project->usesHourBanks()
                    && $this->input('billing_type') !== BillingType::HourBank->value
                    && $project->hourBanks()->exists()) {
                    $validator->errors()->add('billing_type', $this->transText('projects.errors.has_hour_banks'));
                }
            },
        ];
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
}
