<?php

namespace App\Search\Sources;

use App\Domain\Projects\ProjectFilters;
use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Proyectos por nombre o código, o por el nombre de su cliente (D-322) (todos los internos los ven, D-021; un colaborador externo, solo los de sus proyectos, D-134). Los archivados, al final.
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
        $internal = ProjectFilters::matchesInternalGroup($query);
        $like = '%'.$this->escapeLike(Str::lower($query)).'%';

        // Por nombre o código, y también por el nombre de su cliente o, si se busca la empresa o
        // «interno», los internos (D-322). Primero los que casan por su propio nombre o código.
        $projects->where(function (Builder $where) use ($query, $internal): void {
            $this->whereMatches($where, ['projects.name', 'projects.code'], $query);
            $where->orWhereHas('client', fn (Builder $clients) => $this->whereMatches($clients, ['clients.name'], $query));

            if ($internal) {
                $where->orWhere(fn (Builder $own) => $own
                    ->whereNull('projects.client_id')
                    ->where('projects.billing_type', BillingType::Internal->value));
            }
        });

        return array_values($projects
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [ProjectStatus::Archived->value])
            ->orderByRaw('CASE WHEN '.$this->matchExpression('projects.name').' OR '.$this->matchExpression('projects.code').' THEN 0 ELSE 1 END', [$like, $like])
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
