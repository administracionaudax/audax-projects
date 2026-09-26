<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sin estados creados, la primera tarea crea los estados por defecto (TaskStatus::DEFAULTS).
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'hour_bank_id' => null,
            'parent_task_id' => null,
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => null,
            'task_type_id' => null,
            'status_id' => function (): int {
                TaskStatus::ensureDefaults();

                return TaskStatus::defaultStatus()->id;
            },
            'priority' => TaskPriority::Normal,
            'assignee_user_id' => null,
            'start_date' => null,
            'due_date' => null,
            'estimated_minutes' => null,
            'is_billable' => true,
            'is_milestone' => false,
            'position' => 0,
            'created_by' => null,
        ];
    }

    /**
     * En una bolsa (y en su proyecto).
     */
    public function inBank(HourBank $bank): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $bank->project_id,
            'hour_bank_id' => $bank->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => ['assignee_user_id' => $user->id]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_id' => function (): int {
                TaskStatus::ensureDefaults();

                return TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->orderBy('position')->firstOrFail()->id;
            },
        ]);
    }

    public function milestone(): static
    {
        return $this->state(fn (array $attributes) => ['is_milestone' => true, 'estimated_minutes' => null]);
    }

    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $parent->project_id,
            'hour_bank_id' => $parent->hour_bank_id,
            'parent_task_id' => $parent->id,
        ]);
    }
}
