<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\MuteRequest;
use App\Http\Requests\Chat\ReadRequest;
use App\Http\Requests\Chat\StoreDirectRequest;
use App\Http\Requests\Chat\StoreGroupRequest;
use App\Http\Resources\Chat\MessageWindow;
use App\Http\Resources\Chat\PinnedPresenter;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Conversaciones (SPEC §12, D-071): abrir una directa (una por pareja, solo entre internos
 * activos), crear un grupo, silenciar, marcar como leído y la barra de fijados.
 */
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationDirectory $directory) {}

    /**
     * «Nuevo mensaje directo»: abre la directa con esa persona (la crea la primera vez).
     */
    public function storeDirect(StoreDirectRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $target = User::query()->find($request->integer('user_id'));

        if ($target === null) {
            throw ValidationException::withMessages(['user_id' => __('conversations.errors.person_not_found')]);
        }

        $conversation = $this->directory->direct($user, $target);

        return $this->opened($request, $conversation);
    }

    /**
     * «Nuevo grupo»: nombre y personas (internas y activas; quien lo crea entra siempre).
     */
    public function storeGroup(StoreGroupRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', (array) $request->input('user_ids', [])));

        $conversation = $this->directory->group($user, $request->string('name')->toString(), $ids);

        return $this->opened($request, $conversation);
    }

    public function mute(MuteRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $participant = $this->directory->mute($user, $conversation, $request->boolean('muted'));

        return response()->json([
            'muted' => $participant->muted,
            'unread_total' => $this->directory->unreadTotal($user),
        ]);
    }

    /**
     * Leído hasta ese mensaje (al ver el último, SPEC §12). Devuelve lo que queda sin leer.
     */
    public function read(ReadRequest $request, Conversation $conversation, MessageWriter $writer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $messageId = $request->integer('message_id');

        Gate::authorize('view', $conversation);

        if (! $conversation->messages()->withTrashed()->whereKey($messageId)->exists()) {
            throw ValidationException::withMessages(['message_id' => __('conversations.errors.message_not_here')]);
        }

        $writer->markRead($user, $conversation, $messageId);

        return response()->json([
            'unread' => $this->directory->unreadCounts($user, [$conversation->id])[$conversation->id] ?? 0,
            'unread_total' => $this->directory->unreadTotal($user),
        ]);
    }

    public function pinned(Request $request, Conversation $conversation, MessageWindow $window): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return response()->json([
            'pinned' => PinnedPresenter::list($window->pinned($conversation, Gate::allows('moderate', $conversation))),
        ]);
    }

    private function opened(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['id' => $conversation->id, 'url' => "/chat/{$conversation->id}"], 201);
        }

        return redirect()->route('chat.show', $conversation);
    }
}
