<?php

namespace App\Search\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Búsqueda de texto sin mayúsculas y, en PostgreSQL, sin acentos (ILIKE + unaccent si la extensión
 * está instalada); en SQLite, LIKE (sin distinguir mayúsculas ASCII). Las columnas son siempre
 * literales del código, nunca texto que venga de fuera.
 */
trait MatchesText
{
    private static ?bool $unaccentAvailable = null;

    /**
     * Añade (col1 LIKE q OR col2 LIKE q …) a la consulta.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $columns
     */
    protected function whereMatches(Builder $query, array $columns, string $text): void
    {
        $like = '%'.$this->escapeLike(Str::lower($text)).'%';

        $query->where(function (Builder $where) use ($columns, $like): void {
            foreach ($columns as $column) {
                $where->orWhereRaw($this->matchExpression($column), [$like]);
            }
        });
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    protected function matchExpression(string $column): string
    {
        return match ($this->matchMode()) {
            'pgsql_unaccent' => 'unaccent('.$column.") ILIKE unaccent(CAST(? AS text)) ESCAPE '\\'",
            'pgsql' => $column." ILIKE ? ESCAPE '\\'",
            default => 'LOWER('.$column.") LIKE ? ESCAPE '\\'",
        };
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function matchMode(): string
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
}
