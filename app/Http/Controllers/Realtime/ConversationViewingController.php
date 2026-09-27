<?php

namespace App\Http\Controllers\Realtime;

use App\Broadcasting\ChatNoticeThrottle;
use App\Broadcasting\ConversationViewers;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * «Tengo esta conversación abierta» (D-072): la pantalla de la conversación lo renueva cada 30 s
 * mientras está visible y lo retira al cerrarse u ocultarse. Con eso no se avisa (ni en la
 * campana ni en el navegador) a quien ya está leyendo, y la agrupación de avisos vuelve a empezar.
 */
class ConversationViewingController extends Controller
{
    public function __construct(
        private readonly ConversationViewers $viewers,
        private readonly ChatNoticeThrottle $throttle,
    ) {}

    public function store(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        $user = $this->user($request);
        $this->viewers->touch($conversation->id, $user->id);
        $this->throttle->reset($user->id, $conversation->id);

        return response()->noContent();
    }

    public function destroy(Request $request, Conversation $conversation): Response
    {
        // Sin política: solo borra la marca propia (quizá ya sin acceso a la conversación).
        $this->viewers->forget($conversation->id, $this->user($request)->id);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
