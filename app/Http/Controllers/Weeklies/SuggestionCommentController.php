<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
use Illuminate\Support\Facades\Gate;

/**
 * Comentarios de las sugerencias con respuestas, adjuntos, menciones y reacciones (F-165 y F-166).
 * Esqueleto del contrato 10.1 (10.7).
 */
class SuggestionCommentController extends Controller
{
    use PendingDelivery;

    public function store(SuggestionPost $post): never
    {
        Gate::authorize('comment', $post);

        $this->pending('10.7');
    }

    public function update(SuggestionComment $suggestionComment): never
    {
        Gate::authorize('update', $suggestionComment);

        $this->pending('10.7');
    }

    public function destroy(SuggestionComment $suggestionComment): never
    {
        Gate::authorize('delete', $suggestionComment);

        $this->pending('10.7');
    }

    public function react(SuggestionComment $suggestionComment): never
    {
        Gate::authorize('react', $suggestionComment);

        $this->pending('10.7');
    }
}
