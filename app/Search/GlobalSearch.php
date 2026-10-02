<?php

namespace App\Search;

use App\Models\User;
use App\Search\Sources\ClientSource;
use App\Search\Sources\MessageSource;
use App\Search\Sources\PageSource;
use App\Search\Sources\PeopleSource;
use App\Search\Sources\ProjectSource;
use App\Search\Sources\TaskSource;
use Illuminate\Contracts\Container\Container;

/**
 * Búsqueda global (Ctrl/Cmd + K). Reparte la consulta entre las fuentes en orden, con un máximo por
 * fuente para que ninguna acapare los resultados, y respeta el límite total.
 */
class GlobalSearch
{
    public const int MIN_LENGTH = 2;

    public const int MAX_RESULTS = 20;

    /**
     * Fuentes en orden y máximo de resultados de cada una.
     *
     * @var array<class-string<SearchSource>, int>
     */
    protected array $sources = [
        PageSource::class => 4,
        ProjectSource::class => 5,
        TaskSource::class => 6,
        ClientSource::class => 4,
        PeopleSource::class => 4,
        // Fase 6: mensajes, archivos y transcripciones del chat (solo de las conversaciones que ve).
        MessageSource::class => 4,
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

        // 1.ª ronda: cada fuente, hasta su máximo. 2.ª ronda: los huecos que queden, con lo que
        // sobró de cada fuente en orden (si solo una tiene resultados, llega al límite total).
        $found = [];
        foreach (array_keys($this->sources) as $sourceClass) {
            /** @var SearchSource $source */
            $source = $this->container->make($sourceClass);
            $found[$sourceClass] = array_slice($source->search($user, $query, $limit), 0, $limit);
        }

        $results = [];
        $leftovers = [];
        foreach ($this->sources as $sourceClass => $cap) {
            array_push($results, ...array_slice($found[$sourceClass], 0, $cap));
            $leftovers[] = array_slice($found[$sourceClass], $cap);
        }

        foreach ($leftovers as $rest) {
            array_push($results, ...$rest);
        }

        return $this->groupByType(array_slice($results, 0, $limit));

    }

    /**
     * Mantiene juntos los resultados del mismo tipo (el orden de los tipos es el de su primera aparición).
     *
     * @param  list<SearchResult>  $results
     * @return list<SearchResult>
     */
    private function groupByType(array $results): array
    {
        $groups = [];
        foreach ($results as $result) {
            $groups[$result->type][] = $result;
        }

        return array_merge(...array_values($groups));
    }
}
