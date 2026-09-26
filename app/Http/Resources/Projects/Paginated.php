<?php

namespace App\Http\Resources\Projects;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Página de resultados para Inertia con una forma estable (resources/js/types/projects.ts,
 * Paginated<T>): data, meta (página, total…) y enlaces a la anterior y la siguiente.
 */
final class Paginated
{
    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<TItem>  $data
     * @return array{data: list<TItem>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}, links: array{prev: string|null, next: string|null}}
     */
    public static function props(LengthAwarePaginator $paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}
