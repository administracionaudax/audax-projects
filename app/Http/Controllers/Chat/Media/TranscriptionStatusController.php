<?php

namespace App\Http\Controllers\Chat\Media;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consulta ligera del estado de las transcripciones (SPEC §12): GET /chat/transcripciones?mensajes=1,2,3.
 * La usa el navegador cuando no hay tiempo real (o como red de seguridad si se pierde el evento
 * AudioTranscribed) para cambiar «Transcribiendo…» por el texto, y para renovar la URL firmada de un
 * audio que lleva mucho rato en pantalla. Una sola petición para todos los audios pendientes a la
 * vista; solo devuelve los de conversaciones que quien pregunta puede ver (ConversationPolicy::view).
 */
class TranscriptionStatusController extends Controller
{
    public const int MAX_MESSAGES = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mensajes' => ['required', 'string', 'max:1000', 'regex:/^\d{1,10}(,\d{1,10})*$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $ids = array_slice(array_values(array_unique(array_map('intval', explode(',', (string) $validated['mensajes'])))), 0, self::MAX_MESSAGES);

        $messages = Message::query()
            ->whereKey($ids)
            ->where('type', MessageType::Audio)
            ->whereNull('hidden_at')
            ->with(['conversation', ...MediaPayload::RELATIONS])
            ->orderBy('id')
            ->get();

        $gate = Gate::forUser($user);
        $allowed = [];
        $items = [];

        foreach ($messages as $message) {
            $allowed[$message->conversation_id] ??= $gate->allows('view', $message->conversation);

            if (! $allowed[$message->conversation_id]) {
                continue;
            }

            $media = MediaPayload::of($message);
            $items[] = ['id' => $message->id, 'audio' => $media['audio'], 'transcription' => $media['transcription']];
        }

        return response()->json(['messages' => $items]);
    }
}
