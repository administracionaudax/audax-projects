<?php

namespace App\Domain\Weeklies\Suggestions;

use App\Enums\SuggestionStatus;
use App\Http\Controllers\Projects\ProjectController;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionPost;
use App\Models\SuggestionVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consultas de las sugerencias, port de `helpSuggestions.ts` (F-159 a F-168):
 * - solo de tableros activos (los ocultos no salen en el feed ni en el roadmap),
 * - **búsqueda global** (F-162): con texto, se ignoran el tablero y la categoría y se busca en el
 *   título, el detalle y el slug, y también en el nombre de los tableros y categorías,
 * - órdenes: Trending (comentarios, votos, última actividad y fecha), Top (votos y fecha) y Nuevo
 *   (fecha); o un estado del roadmap (última actividad),
 * - el roadmap (F-168): una columna por estado (planned, building_now, beta y completed) en el orden
 *   de su `position`, con «cargar más» por columna.
 */
final class SuggestionQueries
{
    public const array SORTS = ['trending', 'top', 'new'];

    public const int PAGE = 20;

    public const int MAX_LIMIT = 200;

    /**
     * Los tableros (con sus categorías) y cuántas sugerencias tiene cada uno. Quien gestiona ve
     * también los ocultos y las categorías ocultas, para editarlos. Tres consultas.
     *
     * @return list<array<string, mixed>>
     */
    public function boards(bool $includeHidden): array
    {
        $boards = SuggestionBoard::query()
            ->when(! $includeHidden, fn (Builder $query) => $query->where('is_active', true))
            ->with(['categories' => fn ($query) => $query
                ->when(! $includeHidden, fn ($q) => $q->where('is_active', true))
                ->orderBy('position')->orderBy('id')])
            ->orderBy('position')->orderBy('id')
            ->get();

        $counts = SuggestionPost::query()
            ->whereIn('suggestion_board_id', $boards->modelKeys())
            ->selectRaw('suggestion_board_id, suggestion_category_id, count(*) as total')
            ->groupBy('suggestion_board_id', 'suggestion_category_id')
            ->get();

        $boardCounts = [];
        $categoryCounts = [];

        foreach ($counts as $row) {
            $board = (int) $row->getAttribute('suggestion_board_id');
            $total = (int) $row->getAttribute('total');
            $boardCounts[$board] = ($boardCounts[$board] ?? 0) + $total;

            if ($row->getAttribute('suggestion_category_id') !== null) {
                $categoryCounts[(int) $row->getAttribute('suggestion_category_id')] = $total;
            }
        }

        return array_values($boards->map(fn (SuggestionBoard $board): array => SuggestionPresenter::board($board, $boardCounts, $categoryCounts))->all());
    }

    /**
     * El feed (F-162).
     *
     * @param  array{q: string|null, board: int|null, category: int|null, order: string, limit: int}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function feed(User $viewer, array $filters): array
    {
        $query = $this->filtered($filters['q'], $filters['board'], $filters['category']);
        $status = SuggestionStatus::tryFrom($filters['order']);

        if ($status !== null && $status->isRoadmap()) {
            $query->where('status', $status->value)->orderByDesc('last_activity_at')->orderByDesc('created_at');
        } else {
            match ($filters['order']) {
                'new' => $query->orderByDesc('created_at'),
                'top' => $query->orderByDesc('vote_count')->orderByDesc('created_at'),
                default => $query->orderByDesc('comment_count')->orderByDesc('vote_count')->orderByDesc('last_activity_at')->orderByDesc('created_at'),
            };
        }

        $total = (clone $query)->count();
        $posts = $query->orderByDesc('id')->limit($filters['limit'])->with($this->listRelations())->get();

        return [
            'items' => $this->present($posts, $viewer),
            'total' => $total,
            'has_more' => $total > $posts->count(),
        ];
    }

    /**
     * El roadmap (F-168): una columna por estado visible.
     *
     * @param  array{q: string|null, board: int|null, category: int|null, statuses: list<string>, limit: int}  $filters
     * @return list<array{status: string, items: list<array<string, mixed>>, total: int, has_more: bool}>
     */
    public function roadmap(User $viewer, array $filters): array
    {
        $columns = [];

        foreach (self::roadmapStatuses() as $status) {
            if (! in_array($status, $filters['statuses'], true)) {
                continue;
            }

            $query = $this->filtered($filters['q'], $filters['board'], $filters['category'])->where('status', $status);
            $total = (clone $query)->count();
            $posts = $query->orderBy('position')->orderByDesc('last_activity_at')->orderByDesc('id')
                ->limit($filters['limit'])->with($this->listRelations())->get();

            $columns[] = [
                'status' => $status,
                'items' => $this->present($posts, $viewer),
                'total' => $total,
                'has_more' => $total > $posts->count(),
            ];
        }

        return $columns;
    }

    /**
     * «Sugerencias similares» al escribir el título (F-161): las 4 más votadas que contienen el
     * texto, sin la de título idéntico.
     *
     * @return list<array<string, mixed>>
     */
    public function similar(User $viewer, string $title): array
    {
        $title = trim($title);

        if (mb_strlen($title) < 3) {
            return [];
        }

        $posts = $this->filtered($title, null, null)
            ->orderByDesc('vote_count')->orderByDesc('created_at')
            ->limit(5)
            ->with($this->listRelations())
            ->get()
            ->reject(fn (SuggestionPost $post): bool => mb_strtolower($post->title) === mb_strtolower($title))
            ->take(4);

        return $this->present($posts, $viewer);
    }

    /**
     * La sugerencia con todo lo de su detalle (F-164 a F-167), cargado de una vez.
     */
    public function detail(SuggestionPost $post): SuggestionPost
    {
        $user = fn ($query) => $query->select(ProjectController::USER_SUMMARY_COLUMNS);

        return $post->load([
            'board',
            'category',
            'author' => $user,
            'attachments',
            'votes' => fn ($query) => $query->orderBy('id')->with(['user' => $user]),
            'comments' => fn ($query) => $query->with([
                'author' => $user,
                'attachments',
                'reactions' => fn ($q) => $q->with(['user' => $user]),
            ]),
            'statusEvents' => fn ($query) => $query->with(['changer' => $user]),
        ]);
    }

    /** @return list<string> */
    public static function roadmapStatuses(): array
    {
        return array_values(array_filter(SuggestionStatus::values(), fn (string $status): bool => SuggestionStatus::from($status)->isRoadmap()));
    }

    /**
     * @return Builder<SuggestionPost>
     */
    private function filtered(?string $search, ?int $board, ?int $category): Builder
    {
        $activeBoards = SuggestionBoard::query()->select('id')->where('is_active', true);
        $query = SuggestionPost::query()->whereIn('suggestion_board_id', $activeBoards);
        $search = $search === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $search));

        if ($search === '') {
            if ($board !== null) {
                $query->where('suggestion_board_id', $board);
            }

            if ($category !== null) {
                $query->where('suggestion_category_id', $category);
            }

            return $query;
        }

        // Sin escapar % ni _: SQLite y PostgreSQL no comparten el carácter de escape por defecto.
        $like = '%'.mb_strtolower($search).'%';
        $boards = SuggestionBoard::query()->select('id')
            ->where(fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like])->orWhereRaw("lower(coalesce(description, '')) like ?", [$like]));
        $categories = SuggestionCategory::query()->select('id')
            ->where(fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like])->orWhereRaw("lower(coalesce(description, '')) like ?", [$like]));

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('lower(title) like ?', [$like])
            ->orWhereRaw('lower(body) like ?', [$like])
            ->orWhereRaw('lower(slug) like ?', [$like])
            ->orWhereIn('suggestion_board_id', $boards)
            ->orWhereIn('suggestion_category_id', $categories));
    }

    /**
     * @return array<int|string, \Closure|string>
     */
    private function listRelations(): array
    {
        return [
            'author' => fn ($query) => $query->select(ProjectController::USER_SUMMARY_COLUMNS),
            'category:id,name,slug',
        ];
    }

    /**
     * @param  Collection<int, SuggestionPost>  $posts
     * @return list<array<string, mixed>>
     */
    private function present(Collection $posts, User $viewer): array
    {
        $voted = $posts->isEmpty() ? [] : array_fill_keys(
            SuggestionVote::query()->where('user_id', $viewer->id)->whereIn('suggestion_post_id', $posts->modelKeys())->pluck('suggestion_post_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );

        return array_values($posts->map(fn (SuggestionPost $post): array => SuggestionPresenter::post($post, $voted))->all());
    }
}
