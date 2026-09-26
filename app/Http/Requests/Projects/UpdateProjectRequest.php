<?php

namespace App\Http\Requests\Projects;

use App\Enums\BillingType;
use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Http\Requests\Projects\Concerns\ProjectRules;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edición de los datos de un proyecto (ajustes). El gestor principal y los miembros se cambian
 * aparte; archivar tiene su botón. Un proyecto con bolsas no deja de ser de bolsas.
 * Si pasa a «bolsa de horas» teniendo tareas, hay que dar los datos de su primera bolsa
 * (`hour_bank.*`): sus tareas irán a ella (SPEC §8.2, cada tarea pertenece a una bolsa).
 */
class UpdateProjectRequest extends FormRequest
{
    use HourBankRules, ProjectRules {
        ProjectRules::canSetFinancials insteadof HourBankRules;
        ProjectRules::transText insteadof HourBankRules;
    }

    private ?int $tasksWithoutBank = null;

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
     * Tareas que se quedarían sin bolsa: las del proyecto si pasa a «bolsa de horas» (0 si no).
     */
    public function tasksNeedingBank(): int
    {
        $project = $this->project();

        if ($project->usesHourBanks() || $this->input('billing_type') !== BillingType::HourBank->value) {
            return 0;
        }

        return $this->tasksWithoutBank ??= $project->tasks()->whereNull('hour_bank_id')->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->projectRules($this->project());

        if ($this->tasksNeedingBank() > 0) {
            $rules['hour_bank'] = ['required', 'array'];
            $rules = [...$rules, ...$this->hourBankRules('hour_bank.')];
        }

        return $rules;
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
        return [
            ...$this->projectMessages(),
            ...$this->hourBankMessages('hour_bank.'),
            'hour_bank.required' => $this->transText('projects.errors.first_hour_bank_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...$this->projectAttributes(),
            ...$this->hourBankAttributes('hour_bank.'),
        ];
    }
}
