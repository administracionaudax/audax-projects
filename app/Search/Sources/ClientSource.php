<?php

namespace App\Search\Sources;

use App\Models\Client;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;

/**
 * Clientes por nombre o persona de contacto (todos los internos los ven, D-021). Primero los activos.
 */
class ClientSource implements SearchSource
{
    use MatchesText;

    public function search(User $user, string $query, int $limit): array
    {
        if (! $user->can('viewAny', Client::class)) {
            return [];
        }

        $clients = Client::query()->withCount(['projects' => fn ($projects) => $projects->notArchived()]);
        $this->whereMatches($clients, ['name', 'contact_name'], $query);

        return array_values($clients
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $client): SearchResult => new SearchResult(
                type: 'client',
                id: $client->id,
                title: $client->name,
                subtitle: $this->subtitle($client),
                url: '/clientes/'.$client->id,
            ))
            ->all());
    }

    private function subtitle(Client $client): string
    {
        $projects = trans_choice('search.results.projects', (int) $client->projects_count, ['count' => (int) $client->projects_count]);

        return $client->is_active ? $projects : __('search.results.inactive').' · '.$projects;
    }
}
