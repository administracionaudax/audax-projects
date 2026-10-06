<?php

namespace App\Search\Sources;

use App\Enums\ForecastStatus;
use App\Models\ForecastProject;
use App\Models\User;
use App\Search\Concerns\MatchesText;
use App\Search\SearchResult;
use App\Search\SearchSource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proyectos previstos por nombre, por el nombre libre del cliente o por el del cliente (D-306): solo
 * para quien ve la previsión (view-forecast, que ya mira el módulo). Primero los que cuentan.
 */
class ForecastProjectSource implements SearchSource
{
    use MatchesText;

    public function search(User $user, string $query, int $limit): array
    {
        if (! $user->can('view-forecast')) {
            return [];
        }

        $forecasts = ForecastProject::query()->with('client:id,name');
        $forecasts->where(function (Builder $where) use ($query): void {
            $this->whereMatches($where, ['name', 'prospect_name'], $query);
            $where->orWhereHas('client', fn (Builder $client) => $this->whereMatches($client, ['name'], $query));
        });

        return array_values($forecasts
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [ForecastStatus::Open->value, ForecastStatus::Confirmed->value])
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'client_id', 'prospect_name', 'status', 'confidence'])
            ->map(fn (ForecastProject $forecast): SearchResult => new SearchResult(
                type: 'forecast',
                id: $forecast->id,
                title: $forecast->name,
                subtitle: implode(' · ', array_filter([
                    $forecast->clientName(),
                    __('search.results.forecast'),
                    $forecast->status->label(),
                ])),
                url: '/prevision/proyectos/'.$forecast->id,
            ))
            ->all());
    }
}
