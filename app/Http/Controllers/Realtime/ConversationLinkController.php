<?php

namespace App\Http\Controllers\Realtime;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * GET /tiempo-real/conversaciones/{id}/abrir?mensaje={id}: enlace ESTABLE de los avisos (campana
 * y navegador), como /tareas/{id} en la Fase 1 (D-055). Comprueba que aún puedes ver la
 * conversación y lleva a la pantalla del chat que exista al pulsarlo: route('chat.show') si el
 * chat la define; si no, /chat?conversacion={id}. El mensaje va en ?mensaje= para situarse en él.
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
        $url = null;
        $query = [];

        if (Route::has('chat.show')) {
            try {
                $url = route('chat.show', [$conversation->id], false);
            } catch (UrlGenerationException) {
                $url = null;
            }
        }

        if ($url === null) {
            $url = '/chat';
            $query['conversacion'] = $conversation->id;
        }

        if ($messageId !== null) {
            $query['mensaje'] = $messageId;
        }

        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
