<?php

namespace App\Http\Controllers\Chat\Media;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Search\Sources\MessageSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta ligera del estado de las transcripciones (SPEC §12): GET /chat/transcripciones?mensajes=1,2,3.
 * La usa el navegador cuando no hay tiempo real (o como red de seguridad si se pierde el evento
 * AudioTranscribed) para cambiar «Transcribiendo…» por el texto, y para renovar la URL firmada de un
 * audio que lleva mucho rato en pantalla. Una sola petición para todos los audios pendientes a la
 * vista; solo devuelve los de conversaciones que quien pregunta puede ver (la regla de
 * ConversationPolicy::view, comprobada para todas a la vez: MessageSource::visibleConversationIds).
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
            ->orderBy('id')
            ->get();

        // Visibilidad de todas sus conversaciones en una consulta (la regla de ConversationPolicy::view).
        $visible = MessageSource::visibleConversationIds($user, array_values(array_unique($messages->pluck('conversation_id')->all())));
        $messages = $messages->filter(fn (Message $message): bool => in_array($message->conversation_id, $visible, true))->values();
        $messages->load(MediaPayload::RELATIONS);

        return response()->json(['messages' => array_values($messages->map(function (Message $message): array {
            $media = MediaPayload::of($message);

            return ['id' => $message->id, 'audio' => $media['audio'], 'transcription' => $media['transcription']];
        })->all())]);
    }
}
