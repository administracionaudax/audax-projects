<?php

namespace App\Search;

use App\Models\User;
use App\Search\Sources\PageSource;
use App\Search\Sources\PeopleSource;
use Illuminate\Contracts\Container\Container;

/**
 * Búsqueda global (Ctrl/Cmd + K). Reparte la consulta entre las fuentes en orden y respeta el límite total.
 */
class GlobalSearch
{
    public const int MIN_LENGTH = 2;

    public const int MAX_RESULTS = 20;

    /**
     * @var list<class-string<SearchSource>>
     */
    protected array $sources = [
        PageSource::class,
        PeopleSource::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<SearchResult>
     */
    public function search(User $user, string $query, int $limit = self::MAX_RESULTS): array
    {
        $query = trim($query);
        $limit = max(0, min($limit, self::MAX_RESULTS));

        if (mb_strlen($query) < self::MIN_LENGTH || $limit === 0) {
            return [];
        }

        $results = [];

        foreach ($this->sources as $sourceClass) {
            $remaining = $limit - count($results);

            if ($remaining <= 0) {
                break;
            }

            /** @var SearchSource $source */
            $source = $this->container->make($sourceClass);

            array_push($results, ...array_slice($source->search($user, $query, $remaining), 0, $remaining));
        }

        return $results;
    }
}
