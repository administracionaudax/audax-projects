<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Asistente de baja (SPEC §14): a quién pasan las tareas abiertas. `default_assignee_id` vale para
 * todas las que no estén en `assignments` (null = sin asignar). Solo personas internas y activas,
 * y nunca la propia persona que se desactiva (se comprueban todas con una sola consulta).
 */
class DeactivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_assignee_id' => ['nullable', 'integer'],
            'assignments' => ['sometimes', 'array', 'max:1000'],
            'assignments.*.task_id' => ['required', 'integer', 'distinct'],
            'assignments.*.assignee_user_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $candidates = array_filter([
                    'default_assignee_id' => $this->defaultAssignee(),
                    ...array_combine(
                        array_map(fn (int $index): string => "assignments.{$index}.assignee_user_id", array_keys($this->rows())),
                        array_map(fn (array $row): ?int => $this->toId($row['assignee_user_id'] ?? null), $this->rows()),
                    ),
                ], fn (?int $id): bool => $id !== null);

                if ($candidates === []) {
                    return;
                }

                $leaving = $this->route('user');
                $eligible = User::query()
                    ->whereKey(array_values(array_unique($candidates)))
                    ->when($leaving instanceof User, fn ($query) => $query->whereKeyNot($leaving instanceof User ? $leaving->id : 0))
                    ->active()
                    ->internal()
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($candidates as $key => $id) {
                    if (! in_array($id, $eligible, true)) {
                        $validator->errors()->add($key, __('admin.users.errors.assignee'));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'default_assignee_id' => __('admin.attributes.assignee'),
            'assignments.*.assignee_user_id' => __('admin.attributes.assignee'),
            'assignments.*.task_id' => __('admin.attributes.task'),
        ];
    }

    /**
     * @return array<int, int|null> task_id => persona (null = sin asignar)
     */
    public function assignments(): array
    {
        $assignments = [];

        foreach ($this->rows() as $row) {
            $assignments[(int) $row['task_id']] = $this->toId($row['assignee_user_id'] ?? null);
        }

        return $assignments;
    }

    public function defaultAssignee(): ?int
    {
        return $this->toId($this->input('default_assignee_id'));
    }

    /**
     * @return array<int, array{task_id: int|string, assignee_user_id?: int|string|null}>
     */
    private function rows(): array
    {
        $rows = $this->input('assignments', []);

        /** @var array<int, array{task_id: int|string, assignee_user_id?: int|string|null}> */
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function toId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
