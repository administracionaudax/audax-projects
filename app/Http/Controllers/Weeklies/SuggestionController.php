<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Help\HelpCenter;
use App\Domain\Weeklies\Suggestions\SuggestionBoardView;
use App\Domain\Weeklies\Suggestions\SuggestionQueries;
use App\Domain\Weeklies\Suggestions\SuggestionWriter;
use App\Enums\SuggestionStatus;
use App\Events\Weeklies\HelpCenterChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\SuggestionPostRequest;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sugerencias (F-159 a F-168): detalle (/ayuda/sugerencias/{id}, la misma página de la ayuda con la
 * sugerencia abierta), crear, editar, borrar, votar, «similares», estado con nota oficial y orden
 * en el roadmap. El listado (Roadmap y Feedback) es /ayuda?pestana=sugerencias.
 */
class SuggestionController extends Controller
{
    public function show(Request $request, SuggestionPost $post, HelpController $help, HelpCenter $center, SuggestionBoardView $suggestions): Response
    {
        Gate::authorize('view', $post);
        abort_unless($post->board()->where('is_active', true)->exists() || Gate::allows('manageBoards', SuggestionPost::class), 404);

        return $help->page($request, $center, $suggestions, $post);
    }

    public function store(SuggestionPostRequest $request, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('create', SuggestionPost::class);

        /** @var User $user */
        $user = $request->user();
        $post = $writer->create($user, $request->payload(), $request->files());

        HelpCenterChanged::dispatch('suggestions', $post->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('help.suggestions.created')]);

        return redirect()->route('suggestions.show', $post);
    }

    public function update(SuggestionPostRequest $request, SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('update', $post);

        /** @var User $user */
        $user = $request->user();
        $writer->update($post, $user, $request->payload(), $request->files(), $request->removeAttachmentIds());

        return $this->done($post, 'help.suggestions.updated');
    }

    public function destroy(SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $writer->delete($post);

        HelpCenterChanged::dispatch('suggestions', $post->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('help.suggestions.deleted')]);

        return redirect()->route('help.index', ['pestana' => 'sugerencias', 'vista' => 'feedback']);
    }

    public function vote(Request $request, SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('vote', $post);

        /** @var User $user */
        $user = $request->user();
        $writer->toggleVote($post, $user);
        HelpCenterChanged::dispatch('suggestions', $post->id);

        return back();
    }

    /** «Sugerencias similares» mientras se escribe el título (F-161), en JSON. */
    public function similar(Request $request, SuggestionQueries $queries): JsonResponse
    {
        Gate::authorize('create', SuggestionPost::class);

        $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['items' => $queries->similar($user, $request->string('q')->toString())]);
    }

    /** Estado con nota oficial (F-167): solo quien gestiona. */
    public function status(Request $request, SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('moderate', $post);

        $data = $request->validate([
            'status' => ['required', Rule::enum(SuggestionStatus::class)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $event = $writer->changeStatus($post, $user, SuggestionStatus::from((string) $data['status']), isset($data['note']) ? (string) $data['note'] : null);

        if ($event === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('help.suggestions.status_unchanged')]);

            return back();
        }

        activity('suggestions')
            ->causedBy($user)
            ->performedOn($post)
            ->event('status_changed')
            ->withProperties(['from' => $event->from_status?->value, 'to' => $event->to_status->value, 'note' => $event->note])
            ->log('status_changed');

        return $this->done($post, 'help.suggestions.status_saved');
    }

    /** Arrastrar en el roadmap (F-168): columna (estado) y vecinas. */
    public function position(Request $request, SuggestionPost $post, SuggestionWriter $writer): RedirectResponse
    {
        Gate::authorize('moderate', $post);

        $data = $request->validate([
            'status' => ['required', Rule::in(SuggestionQueries::roadmapStatuses())],
            'before_id' => ['nullable', 'integer'],
            'after_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $from = $post->status;
        $status = SuggestionStatus::from((string) $data['status']);
        $writer->move($post, $user, $status, isset($data['before_id']) ? (int) $data['before_id'] : null, isset($data['after_id']) ? (int) $data['after_id'] : null);

        if ($from !== $status) {
            activity('suggestions')
                ->causedBy($user)
                ->performedOn($post)
                ->event('status_changed')
                ->withProperties(['from' => $from->value, 'to' => $status->value, 'note' => null])
                ->log('status_changed');
        }

        HelpCenterChanged::dispatch('suggestions', $post->id);

        return back();
    }

    private function done(SuggestionPost $post, string $message): RedirectResponse
    {
        HelpCenterChanged::dispatch('suggestions', $post->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
