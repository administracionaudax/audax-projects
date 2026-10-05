<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Suggestions\SuggestionWriter;
use App\Enums\SuggestionReaction;
use App\Events\Weeklies\HelpCenterChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\SuggestionCommentRequest;
use App\Models\SuggestionComment;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Comentarios de las sugerencias con respuestas anidadas, adjuntos, menciones y reacciones
 * (F-165 y F-166): comenta y reacciona la plantilla; edita su autor; borra su autor o quien
 * gestiona (con sus respuestas).
 */
class SuggestionCommentController extends Controller
{
    public function store(SuggestionCommentRequest $request, SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('comment', $post);

        /** @var User $user */
        $user = $request->user();
        $writer->comment($post, $user, $request->body(), $request->parentId(), $request->files());

        return $this->done($post->id, 'help.suggestions.comment_created');
    }

    public function update(SuggestionCommentRequest $request, SuggestionComment $suggestionComment, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('update', $suggestionComment);

        /** @var User $user */
        $user = $request->user();
        $writer->updateComment($suggestionComment, $user, $request->body(), $request->files(), $request->removeAttachmentIds());

        return $this->done($suggestionComment->suggestion_post_id, 'help.suggestions.comment_updated');
    }

    public function destroy(SuggestionComment $suggestionComment, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('delete', $suggestionComment);

        $writer->deleteComment($suggestionComment);

        return $this->done($suggestionComment->suggestion_post_id, 'help.suggestions.comment_deleted');
    }

    public function react(Request $request, SuggestionComment $suggestionComment, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('react', $suggestionComment);

        $data = $request->validate(['reaction' => ['required', Rule::enum(SuggestionReaction::class)]]);

        /** @var User $user */
        $user = $request->user();
        $writer->react($suggestionComment, $user, SuggestionReaction::from((string) $data['reaction']));
        HelpCenterChanged::dispatch('suggestions', $suggestionComment->suggestion_post_id);

        return back();
    }

    private function done(int $postId, string $message): RedirectResponse
    {
        HelpCenterChanged::dispatch('suggestions', $postId);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
