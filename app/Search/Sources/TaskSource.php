<?php

namespace App\Search\Sources;

use App\Models\Task;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;

/**
 * Tareas por título (todos los internos las ven, D-021; un colaborador externo, solo las de sus proyectos, D-134). Primero las abiertas y las asignadas a quien
 * busca; se abren en el panel lateral de su proyecto.
 */
class TaskSource implements SearchSource
{
    use MatchesText;

    public function search(User $user, string $query, int $limit): array
    {
        if (! $user->can('viewAny', Task::class)) {
            return [];
        }

        $tasks = Task::query()->visibleTo($user)->with('project:id,name,code');
        $this->whereMatches($tasks, ['tasks.title'], $query);

        return array_values($tasks
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN assignee_user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'project_id', 'completed_at', 'assignee_user_id', 'updated_at'])
            ->map(fn (Task $task): SearchResult => new SearchResult(
                type: 'task',
                id: $task->id,
                title: $task->title,
                subtitle: $task->project->code.' · '.$task->project->name
                    .($task->completed_at !== null ? ' · '.$this->completedLabel() : ''),
                url: '/proyectos/'.$task->project_id.'/tareas?tarea='.$task->id,
            ))
            ->all());
    }

    private function completedLabel(): string
    {
        $label = __('search.results.completed');

        return is_string($label) ? $label : '';
    }
}
