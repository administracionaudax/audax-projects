<?php

namespace App\Domain\DayPlan;

use App\Enums\DayPlanItemStatus;
use App\Models\DayPlanItem;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Desde mis tareas» (docs/PLAN-CARGAS.md §4.2): las tareas de Mis tareas que tiene sentido añadir a
 * un día. Mis tareas abiertas que vencen ese día o antes (vencidas), las que empiezan o están en
 * curso ese día y aquellas en las que imputé en los dos días anteriores (como «ayer» de la lista
 * daily). Sin hitos, sin proyectos archivados y sin las que ya están en el plan de ese día.
 */
final class DayPlanTaskSuggestions
{
    public const int LIMIT = 30;

    /**
     * @return list<array{id: int, title: string, reason: string, due_date: string|null, project: array{id: int, code: string, name: string, color: string}}>
     */
    public function for(User $user, CarbonImmutable $date): array
    {
        $day = $date->toDateString();
        $present = DayPlanItem::query()
            ->where('user_id', $user->id)
            ->where('date', $day)
            ->where('status', '!=', DayPlanItemStatus::Carried->value)
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $logged = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$date->subDays(3)->toDateString(), $date->subDay()->toDateString()])
            ->distinct()
            ->pluck('task_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $tasks = Task::query()
            ->whereNull('completed_at')
            ->where('is_milestone', false)
            ->whereNotIn('id', $present)
            ->whereHas('project', fn (Builder $project) => $project->notArchived()->visibleTo($user))
            ->where(fn (Builder $where) => $where
                ->where(fn (Builder $mine) => $mine
                    ->where('assignee_user_id', $user->id)
                    ->where(fn (Builder $when) => $when
                        ->where('due_date', '<=', $day)
                        ->orWhere(fn (Builder $running) => $running->where('start_date', '<=', $day)->where(fn (Builder $end) => $end->whereNull('due_date')->orWhere('due_date', '>=', $day)))))
                ->orWhereIn('id', $logged))
            ->with('project:id,code,name,color')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'project_id', 'due_date', 'start_date', 'assignee_user_id']);

        $logged = array_flip($logged);

        return array_values($tasks->map(fn (Task $task): array => [
            'id' => $task->id,
            'title' => $task->title,
            'reason' => match (true) {
                $task->due_date !== null && $task->due_date->toDateString() < $day && $task->assignee_user_id === $user->id => 'overdue',
                $task->due_date !== null && $task->due_date->toDateString() === $day && $task->assignee_user_id === $user->id => 'due',
                isset($logged[$task->id]) => 'logged',
                default => 'in_progress',
            },
            'due_date' => $task->due_date?->toDateString(),
            'project' => [
                'id' => $task->project->id,
                'code' => $task->project->code,
                'name' => $task->project->name,
                'color' => $task->project->color,
            ],
        ])->all());
    }
}
