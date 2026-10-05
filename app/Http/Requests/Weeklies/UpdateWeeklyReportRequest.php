<?php

namespace App\Http\Requests\Weeklies;

use App\Enums\WeeklyClientStatus;
use App\Models\WeeklyCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Editar el informe a mano (F-077, D-190): quien gestiona (WeeklyCyclePolicy::update), también con
 * la semana cerrada. Por cliente (en el orden del informe): estado, resumen, siguientes pasos, hitos
 * con fecha y etiquetas; además, el resumen global y los riesgos. Lo demás del informe (los
 * proyectos, la satisfacción y si tenía reportes) no se toca.
 */
final class UpdateWeeklyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle instanceof WeeklyCycle && Gate::allows('update', $cycle);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'global_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'team_risks' => ['sometimes', 'array', 'max:50'],
            'team_risks.*' => ['nullable', 'string', 'max:2000'],
            'client_updates' => ['required', 'array', 'max:500'],
            'client_updates.*.client_id' => ['nullable', 'integer'],
            'client_updates.*.client_name' => ['required', 'string', 'max:255'],
            'client_updates.*.status' => ['required', Rule::enum(WeeklyClientStatus::class)],
            'client_updates.*.executive_summary' => ['present', 'nullable', 'string', 'max:20000'],
            'client_updates.*.next_steps' => ['present', 'array', 'max:50'],
            'client_updates.*.next_steps.*' => ['nullable', 'string', 'max:2000'],
            'client_updates.*.milestones' => ['present', 'array', 'max:50'],
            'client_updates.*.milestones.*.date' => ['nullable', 'string', 'max:40'],
            'client_updates.*.milestones.*.label' => ['nullable', 'string', 'max:2000'],
            'client_updates.*.tags' => ['sometimes', 'array', 'max:50'],
            'client_updates.*.tags.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
