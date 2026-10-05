<?php

namespace App\Domain\Weeklies\Suggestions;

use App\Models\SuggestionCategory;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La pestaña «Sugerencias» de /ayuda (F-159 a F-168), con los filtros en la URL como WeeklySync:
 *   ?vista=roadmap|feedback (por defecto, roadmap), tablero=slug, categoria=slug, q=texto,
 *   orden=trending|top|new|planned|building_now|beta|completed (feedback), estados=planned,beta…
 *   (roadmap), limite=n («cargar más»), nueva=1|bug (abrir el formulario; «bug» lo fija en la
 *   categoría Bugs, F-149) y, en /ayuda/sugerencias/{id}, el detalle.
 */
final class SuggestionBoardView
{
    public const int ROADMAP_PAGE = 24;

    public function __construct(private readonly SuggestionQueries $queries) {}

    /**
     * @return array<string, mixed>
     */
    public function page(Request $request, User $user, ?SuggestionPost $post = null): array
    {
        $gate = Gate::forUser($user);
        $manage = $gate->allows('manageBoards', SuggestionPost::class);
        $view = $request->string('vista')->toString() === 'feedback' ? 'feedback' : 'roadmap';
        $boards = $this->queries->boards($manage);
        $visible = array_values(array_filter($boards, fn (array $board): bool => (bool) $board['is_active']));

        [$boardId, $categoryId] = $this->resolveFilters($visible, $request->string('tablero')->toString(), $request->string('categoria')->toString());

        $q = trim($request->string('q')->toString());
        $q = $q === '' ? null : mb_substr($q, 0, 200);
        $order = $request->string('orden')->toString();
        $order = in_array($order, [...SuggestionQueries::SORTS, ...SuggestionQueries::roadmapStatuses()], true) ? $order : 'trending';
        $statuses = array_values(array_intersect(
            SuggestionQueries::roadmapStatuses(),
            array_filter(explode(',', $request->string('estados')->toString())),
        ));
        $statuses = $statuses === [] ? SuggestionQueries::roadmapStatuses() : $statuses;
        $defaultLimit = $view === 'roadmap' ? self::ROADMAP_PAGE : SuggestionQueries::PAGE;
        $limit = max($defaultLimit, min(SuggestionQueries::MAX_LIMIT, $request->integer('limite', $defaultLimit)));
        $composer = $request->string('nueva')->toString();

        $filters = ['q' => $q, 'board' => $boardId, 'category' => $categoryId];

        return [
            'view' => $view,
            'filters' => [
                'q' => $q,
                'board' => $boardId,
                'category' => $categoryId,
                'order' => $order,
                'statuses' => $statuses,
                'limit' => $limit,
            ],
            'boards' => $boards,
            'bugs_category_id' => $this->bugsCategory(),
            'feed' => $post === null && $view === 'feedback'
                ? $this->queries->feed($user, $filters + ['order' => $order, 'limit' => $limit])
                : null,
            'roadmap' => $post === null && $view === 'roadmap'
                ? $this->queries->roadmap($user, $filters + ['statuses' => $statuses, 'limit' => $limit])
                : null,
            'post' => $post === null ? null : SuggestionPresenter::detail($this->queries->detail($post), $user) + [
                'can' => [
                    'update' => $gate->allows('update', $post),
                    'delete' => $gate->allows('delete', $post),
                    'moderate' => $gate->allows('moderate', $post),
                ],
            ],
            'people' => self::mentionables(),
            'composer' => in_array($composer, ['1', 'bug'], true) ? ($composer === 'bug' ? 'bug' : 'default') : null,
            'can' => [
                'create' => $gate->allows('create', SuggestionPost::class),
                'manage_boards' => $manage,
                'moderate' => $manage,
            ],
        ];
    }

    /**
     * Personas que se pueden mencionar (F-165): la plantilla activa que usa la Weekly.
     *
     * @return list<array{id: int, name: string, avatar: string|null}>
     */
    public static function mentionables(): array
    {
        return array_values(User::query()
            ->active()
            ->role(User::WEEKLY_ROLES)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url])
            ->all());
    }

    /**
     * El tablero y la categoría de los filtros, por su slug, entre los visibles.
     *
     * @param  list<array<string, mixed>>  $boards
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveFilters(array $boards, string $boardSlug, string $categorySlug): array
    {
        $board = null;

        foreach ($boards as $candidate) {
            if ($boardSlug !== '' && $candidate['slug'] === $boardSlug) {
                $board = $candidate;
            }
        }

        if ($categorySlug === '') {
            return [$board === null ? null : (int) $board['id'], null];
        }

        foreach ($board === null ? $boards : [$board] as $candidate) {
            /** @var list<array<string, mixed>> $categories */
            $categories = $candidate['categories'];

            foreach ($categories as $category) {
                if ($category['slug'] === $categorySlug && $category['is_active']) {
                    return [(int) $candidate['id'], (int) $category['id']];
                }
            }
        }

        return [$board === null ? null : (int) $board['id'], null];
    }

    /** La categoría «Bugs» (F-149 y F-169), si está activa en un tablero activo. */
    private function bugsCategory(): ?int
    {
        $id = SuggestionCategory::query()
            ->where('slug', SuggestionCategory::BUGS_SLUG)
            ->where('is_active', true)
            ->whereHas('board', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
