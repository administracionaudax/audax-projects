<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Asistente de baja (SPEC §14): a quién pasan las tareas abiertas y quién pasa a ser gestor
 * principal de sus proyectos (D-032). `default_assignee_id` vale para todas las tareas que no
 * estén en `assignments` (null = sin asignar); en `owners`, null deja el proyecto como está. Solo
 * personas internas y activas, y nunca la propia persona que se desactiva (se comprueban todas
 * con una sola consulta).
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
            'owners' => ['sometimes', 'array', 'max:500'],
            'owners.*.project_id' => ['required', 'integer', 'distinct'],
            'owners.*.owner_user_id' => ['nullable', 'integer'],
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
                    ...array_combine(
                        array_map(fn (int $index): string => "owners.{$index}.owner_user_id", array_keys($this->ownerRows())),
                        array_map(fn (array $row): ?int => $this->toId($row['owner_user_id'] ?? null), $this->ownerRows()),
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
            'owners.*.owner_user_id' => __('admin.attributes.owner'),
            'owners.*.project_id' => __('admin.attributes.project'),
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

    /**
     * @return array<int, int> project_id => nuevo gestor principal (sin los que se quedan igual).
     */
    public function owners(): array
    {
        $owners = [];

        foreach ($this->ownerRows() as $row) {
            $owner = $this->toId($row['owner_user_id'] ?? null);

            if ($owner !== null) {
                $owners[(int) $row['project_id']] = $owner;
            }
        }

        return $owners;
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

    /**
     * @return array<int, array{project_id: int|string, owner_user_id?: int|string|null}>
     */
    private function ownerRows(): array
    {
        $rows = $this->input('owners', []);

        /** @var array<int, array{project_id: int|string, owner_user_id?: int|string|null}> */
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function toId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
