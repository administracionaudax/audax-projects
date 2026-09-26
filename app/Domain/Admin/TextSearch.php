<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Búsqueda de texto en listados (usuarios, clientes) con el mismo criterio que la búsqueda global
 * (App\Search\Sources\PeopleSource): en PostgreSQL sin mayúsculas ni acentos (ILIKE + unaccent si
 * la extensión está instalada); en SQLite, LOWER() LIKE. Nada de SQL propio de un motor sin
 * alternativa para el otro.
 */
final class TextSearch
{
    private static ?bool $unaccentAvailable = null;

    /**
     * Añade «alguna de estas columnas contiene el texto» a la consulta.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $columns  Columnas cualificadas ('users.name'), fijas en el código:
     *                                         nunca llegan de la petición.
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $term, array $columns): Builder
    {
        $term = trim($term);

        if ($term === '' || $columns === []) {
            return $query;
        }

        $like = '%'.self::escapeLike(Str::lower($term)).'%';
        $mode = self::mode();

        return $query->where(function (Builder $where) use ($columns, $like, $mode): void {
            foreach ($columns as $column) {
                $where->orWhereRaw(self::expression($mode, $column), [$like]);
            }
        });
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function expression(string $mode, string $column): string
    {
        return match ($mode) {
            'pgsql_unaccent' => "unaccent(coalesce({$column}, '')) ILIKE unaccent(CAST(? AS text)) ESCAPE '\\'",
            'pgsql' => "coalesce({$column}, '') ILIKE ? ESCAPE '\\'",
            default => "LOWER(coalesce({$column}, '')) LIKE ? ESCAPE '\\'",
        };
    }

    private static function mode(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'default';
        }

        if (self::$unaccentAvailable === null) {
            try {
                self::$unaccentAvailable = DB::table('pg_extension')->where('extname', 'unaccent')->exists();
            } catch (Throwable) {
                self::$unaccentAvailable = false;
            }
        }

        return self::$unaccentAvailable ? 'pgsql_unaccent' : 'pgsql';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
