<?php

namespace App\Domain\Recurring;

use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Sección «Tareas recurrentes» de los Ajustes del proyecto (D-059): sus reglas, las últimas tareas
 * creadas por ellas y las opciones del formulario (miembros activos como responsables, tipos
 * activos y, si el proyecto es de bolsas, sus bolsas abiertas). Se envía como prop diferida
 * (`recurring`) de projects/settings: no suma consultas a la carga de la página.
 * Tipo en resources/js/types/templates.ts (ProjectRecurringSettings).
 */
final class ProjectRecurringSettings
{
    /** Últimas tareas creadas por las reglas que se enseñan. */
    public const int RECENT = 10;

    public function __construct(private readonly RecurringRuleItems $items) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Project $project): array
    {
        $rules = RecurringTaskRule::query()
            ->where('project_id', $project->id)
            ->orderByDesc('is_active')
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        $recent = Task::query()
            ->where('project_id', $project->id)
            ->whereNotNull('recurring_task_rule_id')
            ->with(['status:id,name,category', 'assignee:id,name'])
            ->orderByDesc('occurrence_date')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'project_id', 'title', 'occurrence_date', 'due_date', 'status_id', 'assignee_user_id', 'completed_at']);

        return [
            'rules' => $this->items->build($rules, $project),
            'recent' => $recent->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'occurrence_date' => $task->occurrence_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'status' => ['id' => $task->status->id, 'name' => $task->status->name, 'category' => $task->status->category->value],
                'assignee' => $task->assignee !== null ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            ])->values()->all(),
            'options' => $this->options($project),
            'archived' => $project->status === ProjectStatus::Archived,
            'today' => LocalTime::todayString(),
        ];
    }

    /**
     * @return array{members: list<array{id: int, name: string}>, types: list<array{id: int, name: string}>,
     *     banks: list<array{id: int, name: string}>, uses_hour_banks: bool}
     */
    public function options(Project $project): array
    {
        $members = array_values($project->members()
            ->active()
            ->internal()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all());

        $types = array_values(TaskType::query()->active()->ordered()->orderBy('id')->get(['id', 'name'])
            ->map(fn (TaskType $type): array => ['id' => $type->id, 'name' => $type->name])
            ->all());

        $banks = $project->usesHourBanks()
            ? array_values(HourBank::query()->where('project_id', $project->id)->open()->orderBy('start_date')->orderBy('id')->get(['id', 'name'])
                ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name])
                ->all())
            : [];

        return [
            'members' => $members,
            'types' => $types,
            'banks' => $banks,
            'uses_hour_banks' => $project->usesHourBanks(),
        ];
    }
}
