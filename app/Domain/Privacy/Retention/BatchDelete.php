<?php

namespace App\Domain\Privacy\Retention;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * Borrado por lotes (D-075): cada vuelta elige hasta $batchSize claves y las borra en una sentencia
 * corta fuera de cualquier transacción, así que nunca bloquea la tabla mucho tiempo. Funciona igual
 * en PostgreSQL y en SQLite (sin DELETE … LIMIT).
 */
final class BatchDelete
{
    /**
     * @param  Closure(): Builder  $query  lo que se borra (se construye de nuevo en cada vuelta)
     */
    public static function run(Closure $query, int $batchSize, string $key = 'id'): int
    {
        $batchSize = max(1, $batchSize);
        $deleted = 0;

        do {
            $ids = $query()->orderBy($key)->limit($batchSize)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            $deleted += $query()->whereIn($key, $ids)->delete();
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
