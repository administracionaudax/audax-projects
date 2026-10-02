<?php

namespace App\Http\Controllers\Realtime;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /tiempo-real/conversaciones/{id}/abrir?mensaje={id}: enlace ESTABLE de los avisos (campana
 * y navegador), como /tareas/{id} en la Fase 1 (D-055). Comprueba que aún puedes ver la
 * conversación y lleva a la conversación en el chat (route('chat.show')); el mensaje va en
 * ?mensaje= para situarse en él.
 */
class ConversationLinkController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('view', $conversation);

        $messageId = $request->integer('mensaje');

        return redirect()->to(self::target($conversation, $messageId > 0 ? $messageId : null));
    }

    public static function target(Conversation $conversation, ?int $messageId = null): string
    {
        return route('chat.show', ['conversation' => $conversation->id, ...($messageId === null ? [] : ['mensaje' => $messageId])], false);
    }
}
