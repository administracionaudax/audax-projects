<?php

namespace Database\Factories;

use App\Enums\TimeEntryStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * SOLO para tests y seeders: escribe la fila directamente, sin las validaciones del SPEC §7.
 * La app crea entradas siempre con App\Domain\Time\TimeEntryWriter. El proyecto y la bolsa se
 * copian de la tarea, y el modelo recalcula la bolsa al guardar.
 *
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => Task::factory(),
            'project_id' => fn (array $attributes): int => Task::query()->whereKey($attributes['task_id'])->firstOrFail()->project_id,
            'hour_bank_id' => fn (array $attributes): ?int => Task::query()->whereKey($attributes['task_id'])->firstOrFail()->hour_bank_id,
            'date' => now()->toDateString(),
            'minutes' => 60,
            'description' => null,
            'is_billable' => true,
            'status' => TimeEntryStatus::Draft,
        ];
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes) => [
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'hour_bank_id' => $task->hour_bank_id,
        ]);
    }

    public function minutes(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['minutes' => $minutes]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => ['date' => $date]);
    }

    public function status(TimeEntryStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'locked_at' => $status === TimeEntryStatus::Locked ? now() : null,
        ]);
    }
}
