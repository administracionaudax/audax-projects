<?php

namespace App\Http\Controllers\Chat\Media;

use App\Domain\Chat\MessageWriter;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Publicar en el chat con adjuntos y/o un audio (SPEC §12): POST /chat/{conversación}/multimedia,
 * en JSON para que el navegador muestre el progreso de la subida y pueda cancelarla
 * (resources/js/components/chat/media/send-with-media.ts). Escribe SIEMPRE MessageWriter (D-069),
 * que guarda el audio con su transcripción obligatoria (D-070) y dispara MessagePosted.
 */
class MessageMediaController extends Controller
{
    public function __construct(private readonly MessageWriter $writer) {}

    public function store(StoreMediaMessageRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = $this->writer->post(
            $user,
            $conversation,
            $request->messageBody(),
            $request->parentMessageId(),
            $request->attachedFiles(),
            $request->audioFile(),
            $request->audioDurationMs(),
        );

        $message->load(MediaPayload::RELATIONS);

        return response()->json(['message' => MediaPayload::message($message)], 201);
    }
}
