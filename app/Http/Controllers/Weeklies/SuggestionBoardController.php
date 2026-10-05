<?php

namespace App\Http\Controllers\Weeklies;

use App\Events\Weeklies\HelpCenterChanged;
use App\Http\Controllers\Controller;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tableros y categorías de las sugerencias (F-160): crear, editar, ocultar (is_active) y reordenar;
 * solo quien gestiona. Un tablero con sugerencias no se borra (se oculta); una categoría sí: sus
 * sugerencias se quedan sin categoría. La categoría «Bugs» (F-169) no se puede borrar: la usa
 * «Reportar un bug» (F-149).
 */
class SuggestionBoardController extends Controller
{
    public function storeBoard(Request $request): RedirectResponse
    {
        $this->manage();
        $data = $this->validateItem($request);

        /** @var User $user */
        $user = $request->user();
        SuggestionBoard::query()->create([
            'name' => $data['name'],
            'slug' => $this->slug(SuggestionBoard::query(), $data['slug'] ?? $data['name']),
            'description' => $data['description'],
            'is_active' => $data['is_active'] ?? true,
            'position' => (int) SuggestionBoard::query()->max('position') + 1,
            'created_by' => $user->id,
        ]);

        return $this->done('help.suggestions.board_created');
    }

    public function updateBoard(Request $request, SuggestionBoard $board): RedirectResponse
    {
        $this->manage();
        $data = $this->validateItem($request);

        $board->update([
            'name' => $data['name'],
            'slug' => $data['slug'] !== null ? $this->slug(SuggestionBoard::query()->whereKeyNot($board->id), $data['slug']) : $board->slug,
            'description' => $data['description'],
            'is_active' => $data['is_active'] ?? $board->is_active,
        ]);

        return $this->done('help.suggestions.board_updated');
    }

    public function destroyBoard(SuggestionBoard $board): RedirectResponse
    {
        $this->manage();

        if ($board->posts()->exists()) {
            throw ValidationException::withMessages(['board' => __('help.suggestions.board_not_empty')]);
        }

        if ($board->categories()->where('slug', SuggestionCategory::BUGS_SLUG)->exists()) {
            throw ValidationException::withMessages(['board' => __('help.suggestions.bugs_locked')]);
        }

        $board->delete();

        return $this->done('help.suggestions.board_deleted');
    }

    public function reorderBoards(Request $request): RedirectResponse
    {
        $this->manage();

        $this->reorder(SuggestionBoard::query()->pluck('id')->all(), $request, SuggestionBoard::class);

        return $this->done('help.suggestions.reordered');
    }

    public function storeCategory(Request $request, SuggestionBoard $board): RedirectResponse
    {
        $this->manage();
        $data = $this->validateItem($request);

        /** @var User $user */
        $user = $request->user();
        $board->categories()->create([
            'name' => $data['name'],
            'slug' => $this->slug(SuggestionCategory::query()->where('suggestion_board_id', $board->id), $data['slug'] ?? $data['name']),
            'description' => $data['description'],
            'is_active' => $data['is_active'] ?? true,
            'position' => (int) SuggestionCategory::query()->where('suggestion_board_id', $board->id)->max('position') + 1,
            'created_by' => $user->id,
        ]);

        return $this->done('help.suggestions.category_created');
    }

    public function updateCategory(Request $request, SuggestionCategory $category): RedirectResponse
    {
        $this->manage();
        $data = $this->validateItem($request);
        $bugs = $category->slug === SuggestionCategory::BUGS_SLUG;

        $category->update([
            'name' => $data['name'],
            // El slug de «Bugs» no cambia: es como la encuentra «Reportar un bug».
            'slug' => ! $bugs && $data['slug'] !== null
                ? $this->slug(SuggestionCategory::query()->where('suggestion_board_id', $category->suggestion_board_id)->whereKeyNot($category->id), $data['slug'])
                : $category->slug,
            'description' => $data['description'],
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        return $this->done('help.suggestions.category_updated');
    }

    public function destroyCategory(SuggestionCategory $category): RedirectResponse
    {
        $this->manage();

        if ($category->slug === SuggestionCategory::BUGS_SLUG) {
            throw ValidationException::withMessages(['category' => __('help.suggestions.bugs_locked')]);
        }

        $category->delete();

        return $this->done('help.suggestions.category_deleted');
    }

    public function reorderCategories(Request $request, SuggestionBoard $board): RedirectResponse
    {
        $this->manage();

        $this->reorder($board->categories()->pluck('id')->all(), $request, SuggestionCategory::class);

        return $this->done('help.suggestions.reordered');
    }

    private function manage(): void
    {
        Gate::authorize('manageBoards', SuggestionPost::class);
    }

    private function done(string $message): RedirectResponse
    {
        HelpCenterChanged::dispatch('suggestions');
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }

    /**
     * @return array{name: string, slug: string|null, description: string|null, is_active: bool|null}
     */
    private function validateItem(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $description = trim((string) ($data['description'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));

        return [
            'name' => trim((string) $data['name']),
            'slug' => $slug === '' ? null : $slug,
            'description' => $description === '' ? null : $description,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
        ];
    }

    /**
     * Slug único dentro del ámbito de $query.
     *
     * @param  Builder<SuggestionBoard>|Builder<SuggestionCategory>  $query
     */
    private function slug($query, string $value): string
    {
        $base = Str::limit(Str::slug($value), 70, '') ?: 'tablero';
        $slug = $base;
        $suffix = 2;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<mixed>  $existing
     * @param  class-string<SuggestionBoard>|class-string<SuggestionCategory>  $model
     */
    private function reorder(array $existing, Request $request, string $model): void
    {
        $ids = array_values(array_map('intval', $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids']));
        $current = array_map('intval', $existing);
        sort($current);
        $sorted = $ids;
        sort($sorted);

        if ($current !== $sorted) {
            throw ValidationException::withMessages(['ids' => __('help.reorder_stale')]);
        }

        DB::transaction(function () use ($ids, $model): void {
            foreach ($ids as $position => $id) {
                $model::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }
}
