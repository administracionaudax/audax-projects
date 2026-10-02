<?php

namespace App\Http\Controllers\Realtime;

use App\Broadcasting\PresenceBoard;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /tiempo-real/presencia: presencia SIN tiempo real. Cada pestaña manda su estado una vez
 * por minuto y recibe el de los demás en la misma respuesta (una sola petición). Con Reverb no se
 * usa: la presencia sale del canal presence «online».
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, PresenceBoard $board): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(PresenceBoard::STATUSES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $board->beat($user->id, (string) $validated['status']);

        return response()->json(['users' => (object) $board->statuses()]);
    }
}
