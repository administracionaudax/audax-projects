<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Acciones masivas (SPEC §6): cambiar estado, responsable, fechas o bolsa de varias tareas del
 * proyecto a la vez. Solo cambian los campos enviados. Autoriza el controlador.
 */
class BulkUpdateTasksRequest extends FormRequest
{
    use TaskFieldRules;

    public const int MAX_TASKS = 200;

    public const array FIELDS = ['status_id', 'assignee_user_id', 'start_date', 'due_date', 'hour_bank_id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $fields = $this->taskFieldRules(partial: true);

        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_TASKS],
            'ids.*' => ['integer', 'distinct'],
            ...array_intersect_key($fields, array_flip(self::FIELDS)),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->changes() === []) {
                    $validator->errors()->add('ids', __('tasks.errors.bulk_empty'));
                }
            },
        ];
    }

    /**
     * Campos que se cambian (los presentes en la petición).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return array_intersect_key($this->all(), array_flip(self::FIELDS));
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->input('ids', [])));
    }
}
