<?php

namespace App\Http\Requests\Recurring;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Http\Requests\Templates\TemplateStructure;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\TaskType;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear o editar una tarea recurrente de un proyecto (SPEC §4.3, D-059), para quien gestiona el
 * proyecto:
 * - plantilla de la tarea: título, descripción, tipo activo, responsable (un miembro activo del
 *   proyecto), bolsa (obligatoria y abierta si el proyecto es de bolsas), estimación y prioridad,
 * - regla: semanal (cada 1-12 semanas, día 1-7 con lunes = 1) o mensual (cada 1-12 meses, día
 *   1-31; si el mes no lo tiene, el último), vencimiento a 0-60 días, desde y hasta (≥ desde),
 *   ambas entre el 01/01/2000 y el 31/12/2100 (RecurringTaskRule::MIN_DATE y MAX_DATE),
 * - un proyecto archivado no admite reglas activas.
 */
class RecurringRuleRequest extends FormRequest
{
    public const int MAX_INTERVAL = 12;

    public const int MAX_DUE_OFFSET = 60;

    public function authorize(): bool
    {
        $rule = $this->route('rule');

        // La regla tiene que ser del proyecto de la URL.
        abort_if($rule instanceof RecurringTaskRule && $rule->project_id !== $this->project()->id, 404);

        return $rule instanceof RecurringTaskRule
            ? $this->user()?->can('update', $rule) ?? false
            : $this->user()?->can('create', [RecurringTaskRule::class, $this->project()]) ?? false;
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->route('project');
    }

    protected function prepareForValidation(): void
    {
        // Solo se guarda el día que usa la frecuencia elegida.
        if ($this->input('frequency') === RecurringTaskRule::WEEKLY) {
            $this->merge(['month_day' => null]);
        } elseif ($this->input('frequency') === RecurringTaskRule::MONTHLY) {
            $this->merge(['weekday' => null]);
        }

        if (! $this->project()->usesHourBanks()) {
            $this->merge(['hour_bank_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'task_type_id' => ['nullable', 'integer'],
            'assignee_user_id' => ['nullable', 'integer'],
            'hour_bank_id' => [$this->project()->usesHourBanks() ? 'required' : 'nullable', 'integer'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:'.TemplateStructure::MAX_ESTIMATE_MINUTES],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'frequency' => ['required', Rule::in([RecurringTaskRule::WEEKLY, RecurringTaskRule::MONTHLY])],
            'interval' => ['required', 'integer', 'min:1', 'max:'.self::MAX_INTERVAL],
            'weekday' => ['nullable', 'required_if:frequency,'.RecurringTaskRule::WEEKLY, 'integer', 'min:1', 'max:7'],
            'month_day' => ['nullable', 'required_if:frequency,'.RecurringTaskRule::MONTHLY, 'integer', 'min:1', 'max:31'],
            'due_offset_days' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DUE_OFFSET],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.RecurringTaskRule::MIN_DATE, 'before_or_equal:'.RecurringTaskRule::MAX_DATE],
            // Desde ya es de 2000 en adelante, así que hasta (≥ desde) también.
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:'.RecurringTaskRule::MAX_DATE],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->project();

            if ($this->boolean('is_active') && $project->status === ProjectStatus::Archived) {
                $validator->errors()->add('is_active', $this->text('templates.errors.rule_project_archived'));
            }

            if (is_numeric($this->input('task_type_id')) && ! $validator->errors()->has('task_type_id')
                && ! TaskType::query()->active()->whereKey((int) $this->input('task_type_id'))->exists()) {
                $validator->errors()->add('task_type_id', $this->text('templates.errors.type_invalid'));
            }

            if (is_numeric($this->input('assignee_user_id')) && ! $validator->errors()->has('assignee_user_id')) {
                $isMember = $project->members()->active()->internal()->whereKey((int) $this->input('assignee_user_id'))->exists();
                if (! $isMember) {
                    $validator->errors()->add('assignee_user_id', $this->text('templates.errors.rule_assignee'));
                }
            }

            if ($project->usesHourBanks() && is_numeric($this->input('hour_bank_id')) && ! $validator->errors()->has('hour_bank_id')) {
                $open = HourBank::query()->whereKey((int) $this->input('hour_bank_id'))->where('project_id', $project->id)->open()->exists();
                if (! $open) {
                    $validator->errors()->add('hour_bank_id', $this->text('templates.errors.rule_bank_invalid'));
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $dateRange = $this->text('templates.errors.rule_date_range', [
            'min' => CarbonImmutable::parse(RecurringTaskRule::MIN_DATE)->format('d/m/Y'),
            'max' => CarbonImmutable::parse(RecurringTaskRule::MAX_DATE)->format('d/m/Y'),
        ]);

        return [
            'starts_on.after_or_equal' => $dateRange,
            'starts_on.before_or_equal' => $dateRange,
            'ends_on.before_or_equal' => $dateRange,
            'hour_bank_id.required' => $this->text('templates.errors.rule_bank_required'),
            'weekday.required_if' => $this->text('templates.errors.rule_weekday'),
            'weekday.min' => $this->text('templates.errors.rule_weekday'),
            'weekday.max' => $this->text('templates.errors.rule_weekday'),
            'month_day.required_if' => $this->text('templates.errors.rule_month_day'),
            'month_day.min' => $this->text('templates.errors.rule_month_day'),
            'month_day.max' => $this->text('templates.errors.rule_month_day'),
            'interval.min' => $this->text('templates.errors.rule_interval'),
            'interval.max' => $this->text('templates.errors.rule_interval'),
            'due_offset_days.min' => $this->text('templates.errors.rule_due_offset', ['max' => self::MAX_DUE_OFFSET]),
            'due_offset_days.max' => $this->text('templates.errors.rule_due_offset', ['max' => self::MAX_DUE_OFFSET]),
            'ends_on.after_or_equal' => $this->text('templates.errors.rule_ends_before'),
            'estimated_minutes.min' => $this->text('templates.errors.estimate_range', ['max' => Duration::format(TemplateStructure::MAX_ESTIMATE_MINUTES)]),
            'estimated_minutes.max' => $this->text('templates.errors.estimate_range', ['max' => Duration::format(TemplateStructure::MAX_ESTIMATE_MINUTES)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach (['title', 'description', 'task_type_id', 'assignee_user_id', 'hour_bank_id', 'estimated_minutes', 'priority',
            'frequency', 'interval', 'weekday', 'month_day', 'due_offset_days', 'starts_on', 'ends_on', 'is_active'] as $field) {
            $attributes[$field] = $this->text("templates.attributes.{$field}");
        }

        return $attributes;
    }

    /**
     * Datos de la regla que se guardan.
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        $data = $this->validated();
        $description = $data['description'] ?? null;

        return [
            'title' => trim((string) $data['title']),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'task_type_id' => $this->intOrNull($data['task_type_id'] ?? null),
            'assignee_user_id' => $this->intOrNull($data['assignee_user_id'] ?? null),
            'hour_bank_id' => $this->intOrNull($data['hour_bank_id'] ?? null),
            'estimated_minutes' => $this->intOrNull($data['estimated_minutes'] ?? null),
            'priority' => (string) $data['priority'],
            'frequency' => (string) $data['frequency'],
            'interval' => (int) $data['interval'],
            'weekday' => $this->intOrNull($data['weekday'] ?? null),
            'month_day' => $this->intOrNull($data['month_day'] ?? null),
            'due_offset_days' => (int) $data['due_offset_days'],
            'starts_on' => (string) $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
