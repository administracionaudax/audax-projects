<?php

namespace App\Http\Requests\Templates;

use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Aplicar plantilla» en los Ajustes de un proyecto existente (D-058): quien gestiona el proyecto
 * elige una plantilla activa, la fecha desde la que se cuentan sus días y, si el proyecto es de
 * bolsas, la bolsa abierta a la que irán todas sus tareas. Las tareas que ya hay no cambian.
 */
class ApplyTemplateRequest extends FormRequest
{
    private ?ProjectTemplate $template = null;

    public function authorize(): bool
    {
        return $this->user()?->can('listForProject', [ProjectTemplate::class, $this->project()]) ?? false;
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
            'template_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'hour_bank_id' => [$this->project()->usesHourBanks() ? 'required' : 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = $this->project();

            if ($project->status === ProjectStatus::Archived) {
                $validator->errors()->add('template_id', $this->text('templates.errors.project_archived'));

                return;
            }

            if ($this->template() === null) {
                $validator->errors()->add('template_id', $this->text('templates.errors.template_unavailable'));
            }

            if ($project->usesHourBanks() && $this->bank() === null) {
                $validator->errors()->add('hour_bank_id', $this->text('templates.errors.bank_invalid'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hour_bank_id.required' => $this->text('templates.errors.bank_required'),
            'template_id.required' => $this->text('templates.errors.template_unavailable'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'template_id' => $this->text('templates.attributes.template_id'),
            'start_date' => $this->text('templates.attributes.start_date'),
            'hour_bank_id' => $this->text('templates.attributes.hour_bank_id'),
        ];
    }

    /**
     * La plantilla elegida, si está activa y fuera de la papelera.
     */
    public function template(): ?ProjectTemplate
    {
        return $this->template ??= ProjectTemplate::query()
            ->whereKey($this->integer('template_id'))
            ->where('is_active', true)
            ->first();
    }

    /**
     * La bolsa elegida, si es de este proyecto y está abierta (solo en proyectos de bolsas).
     */
    public function bank(): ?HourBank
    {
        if (! $this->project()->usesHourBanks()) {
            return null;
        }

        return HourBank::query()
            ->whereKey($this->integer('hour_bank_id'))
            ->where('project_id', $this->project()->id)
            ->open()
            ->first();
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('start_date'))->startOfDay();
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
