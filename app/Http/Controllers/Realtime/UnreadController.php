<?php

namespace App\Http\Controllers\Realtime;

use App\Broadcasting\UnreadCounts;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /tiempo-real/no-leidos: mensajes sin leer por conversación y total (sin las silenciadas).
 * Lo pide useUnreadCounter() al empezar y, sin tiempo real, cada 30 s; con tiempo real, solo al
 * volver a la pestaña, al reconectar o tras una lectura en otro dispositivo.
 */
class UnreadController extends Controller
{
    public function __invoke(Request $request, UnreadCounts $counts): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $unread = $counts->for($user);

        return response()->json([
            'total' => $unread['total'],
            // Objeto aunque esté vacío ({} y no []): el cliente lo trata como diccionario.
            'conversations' => (object) $unread['conversations'],
            'muted' => $unread['muted'],
        ]);
    }
}
