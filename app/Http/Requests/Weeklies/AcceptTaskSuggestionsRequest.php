<?php

namespace App\Http\Requests\Weeklies;

use App\Domain\Weeklies\Tasks\TaskSuggester;
use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear las tareas sugeridas revisadas (F-062, D-204): cada una con su título, el proyecto (y la bolsa
 * en un proyecto de bolsas) que ha elegido la persona, y la prioridad y la entrega si las pone. Las
 * reglas del dominio (bolsa abierta del proyecto…) las aplica TaskWriter; el permiso para crear en
 * el proyecto, TaskSuggester. `dismiss`: las propuestas que se descartan a la vez.
 */
final class AcceptTaskSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tasks' => ['required_without:dismiss', 'array', 'max:'.TaskSuggester::MAX_ITEMS],
            'tasks.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.project_id' => ['required', 'integer'],
            'tasks.*.hour_bank_id' => ['nullable', 'integer'],
            'tasks.*.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'dismiss' => ['sometimes', 'array', 'max:'.TaskSuggester::MAX_ITEMS],
            'dismiss.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tasks.*.title.required' => __('weeklies.tasks.errors.title'),
            'tasks.*.project_id.required' => __('weeklies.tasks.errors.project'),
        ];
    }

    /**
     * @return list<array{key: string, title: string, project_id: int, hour_bank_id: int|null, priority: string|null, due_date: string|null}>
     */
    public function accepted(): array
    {
        $rows = [];

        foreach ((array) $this->validated('tasks', []) as $row) {
            $rows[] = [
                'key' => (string) $row['key'],
                'title' => trim((string) $row['title']),
                'project_id' => (int) $row['project_id'],
                'hour_bank_id' => isset($row['hour_bank_id']) ? (int) $row['hour_bank_id'] : null,
                'priority' => isset($row['priority']) ? (string) $row['priority'] : null,
                'due_date' => isset($row['due_date']) ? (string) $row['due_date'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function dismissed(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('dismiss', [])));
    }
}
