<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Support\RichText;
use Illuminate\Validation\Rule;

/**
 * Forma de los campos de una tarea. Las reglas del dominio (bolsa abierta del proyecto, subtareas de
 * un nivel, estimación del padre, responsable interno activo…) las aplica App\Domain\Tasks\TaskWriter.
 */
trait TaskFieldRules
{
    /**
     * Estimación máxima: 999 h (DurationInput de las estimaciones).
     */
    public const int MAX_ESTIMATE_MINUTES = 999 * 60;

    /**
     * @return array<string, list<mixed>>
     */
    protected function taskFieldRules(bool $partial): array
    {
        $optional = $partial ? ['sometimes'] : [];

        return [
            'title' => [...$optional, 'required', 'string', 'max:255'],
            'description' => [...$optional, 'nullable', 'string', 'max:'.RichText::MAX_LENGTH],
            'status_id' => [...$optional, 'nullable', 'integer'],
            'priority' => [...$optional, 'nullable', Rule::enum(TaskPriority::class)],
            'assignee_user_id' => [...$optional, 'nullable', 'integer'],
            'hour_bank_id' => [...$optional, 'nullable', 'integer'],
            'task_type_id' => [...$optional, 'nullable', 'integer'],
            'start_date' => [...$optional, 'nullable', 'date_format:Y-m-d'],
            'due_date' => [...$optional, 'nullable', 'date_format:Y-m-d'],
            'estimated_minutes' => [...$optional, 'nullable', 'integer', 'min:1', 'max:'.self::MAX_ESTIMATE_MINUTES],
            'is_billable' => [...$optional, 'nullable', 'boolean'],
            'is_milestone' => [...$optional, 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return (array) __('tasks.attributes');
    }
}
