<?php

namespace App\Search\Sources;

use App\Models\User;
use App\Search\SearchResult;
use App\Search\SearchSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Personas: usuarios internos activos, por nombre o correo. Sin mayúsculas ni acentos en PostgreSQL
 * (ILIKE + unaccent si la extensión está instalada); en SQLite, LIKE (sin distinguir mayúsculas ASCII).
 *
 * Fase 0: aún no hay ficha de persona, así que url es null.
 */
class PeopleSource implements SearchSource
{
    /**
     * Expresiones SQL por modo y columna (literales, sin interpolar nada que venga de fuera).
     *
     * @var array<string, array{name: literal-string, email: literal-string}>
     */
    private const array EXPRESSIONS = [
        'pgsql_unaccent' => [
            'name' => "unaccent(name) ILIKE unaccent(CAST(? AS text)) ESCAPE '\\'",
            'email' => "unaccent(email) ILIKE unaccent(CAST(? AS text)) ESCAPE '\\'",
        ],
        'pgsql' => [
            'name' => "name ILIKE ? ESCAPE '\\'",
            'email' => "email ILIKE ? ESCAPE '\\'",
        ],
        'default' => [
            'name' => "LOWER(name) LIKE ? ESCAPE '\\'",
            'email' => "LOWER(email) LIKE ? ESCAPE '\\'",
        ],
    ];

    private static ?bool $unaccentAvailable = null;

    public function search(User $user, string $query, int $limit): array
    {
        $like = '%'.$this->escapeLike(Str::lower($query)).'%';

        $people = User::query()
            ->active()
            ->internal()
            ->with('department:id,name')
            ->where(function (Builder $where) use ($like): void {
                $expressions = self::EXPRESSIONS[$this->mode()];

                $where->whereRaw($expressions['name'], [$like])
                    ->orWhereRaw($expressions['email'], [$like]);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'department_id']);

        return array_values($people->map(fn (User $person): SearchResult => new SearchResult(
            type: 'person',
            id: $person->id,
            title: $person->name,
            subtitle: $person->department !== null ? $person->department->name.' · '.$person->email : $person->email,
            url: null,
        ))->all());
    }

    private function mode(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'default';
        }

        return $this->unaccentAvailable() ? 'pgsql_unaccent' : 'pgsql';
    }

    private function unaccentAvailable(): bool
    {
        if (self::$unaccentAvailable === null) {
            try {
                self::$unaccentAvailable = DB::table('pg_extension')->where('extname', 'unaccent')->exists();
            } catch (Throwable) {
                self::$unaccentAvailable = false;
            }
        }

        return self::$unaccentAvailable;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
