<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\MessageWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ModerateRequest;
use App\Http\Requests\Chat\PinRequest;
use App\Http\Requests\Chat\ReactRequest;
use App\Http\Resources\Chat\MessageWindow;
use App\Http\Resources\Chat\PinnedPresenter;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Acciones sobre un mensaje (SPEC §12): reaccionar (conmutador), fijar o desfijar y ocultar o
 * volver a mostrar (moderación del admin, auditada; D-071). Cada una la autoriza MessageWriter con
 * MessagePolicy; antes, quien no ve la conversación recibe siempre un 403.
 */
class MessageActionController extends Controller
{
    use RespondsWithMessages;

    public function __construct(private readonly MessageWriter $writer) {}

    public function react(ReactRequest $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message->conversation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->toggleReaction($user, $message, $request->string('emoji')->toString());

        return $this->messageResponse($message, $user);
    }

    public function pin(PinRequest $request, Message $message, MessageWindow $window): JsonResponse
    {
        $conversation = $message->conversation;
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->setPinned($user, $message, $request->boolean('pinned'));

        return $this->messageResponse($message, $user, extra: [
            'pinned' => PinnedPresenter::list($window->pinned($conversation, Gate::allows('moderate', $conversation))),
        ]);
    }

    public function moderate(ModerateRequest $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message->conversation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->setHidden($user, $message, $request->boolean('hidden'));

        return $this->messageResponse($message, $user);
    }
}
