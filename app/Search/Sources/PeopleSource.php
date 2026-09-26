<?php

namespace App\Search\Sources;

use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;

/**
 * Personas: usuarios internos activos, por nombre o correo (sin mayúsculas ni acentos: MatchesText).
 * Aún no hay ficha de persona, así que url es null.
 */
class PeopleSource implements SearchSource
{
    use MatchesText;

    public function search(User $user, string $query, int $limit): array
    {
        $people = User::query()
            ->active()
            ->internal()
            ->with('department:id,name');
        $this->whereMatches($people, ['name', 'email'], $query);

        return array_values($people
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'department_id'])
            ->map(fn (User $person): SearchResult => new SearchResult(
                type: 'person',
                id: $person->id,
                title: $person->name,
                subtitle: $person->department !== null ? $person->department->name.' · '.$person->email : $person->email,
                url: null,
            ))
            ->all());
    }
}
