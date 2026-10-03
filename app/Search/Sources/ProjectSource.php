<?php

namespace App\Search\Sources;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;

/**
 * Proyectos por nombre o código (todos los internos los ven, D-021; un colaborador externo, solo los de sus proyectos, D-134). Los archivados, al final.
 */
class ProjectSource implements SearchSource
{
    use MatchesText;

    public function search(User $user, string $query, int $limit): array
    {
        if (! $user->can('viewAny', Project::class)) {
            return [];
        }

        $projects = Project::query()->visibleTo($user)->with('client:id,name');
        $this->whereMatches($projects, ['projects.name', 'projects.code'], $query);

        return array_values($projects
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [ProjectStatus::Archived->value])
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'client_id', 'status'])
            ->map(fn (Project $project): SearchResult => new SearchResult(
                type: 'project',
                id: $project->id,
                title: $project->name,
                subtitle: $project->code.' · '.($project->client->name ?? $this->label('internal'))
                    .($project->status === ProjectStatus::Archived ? ' · '.$this->label('archived') : ''),
                url: '/proyectos/'.$project->id,
            ))
            ->all());
    }

    private function label(string $key): string
    {
        $label = __('search.results.'.$key);

        return is_string($label) ? $label : $key;
    }
}
