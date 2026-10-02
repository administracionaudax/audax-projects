<?php

namespace App\Http\Controllers\Chat;

use App\Http\Resources\Chat\ConversationAbilities;
use App\Http\Resources\Chat\MessagePresenter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;

/**
 * Respuestas JSON del chat con los mensajes presentados para quien mira (MessagePresenter).
 */
trait RespondsWithMessages
{
    /**
     * @param  array<string, mixed>  $extra
     */
    protected function messageResponse(Message $message, User $viewer, int $status = 200, array $extra = []): JsonResponse
    {
        /** @var Conversation $conversation */
        $conversation = $message->conversation;
        $presented = (new MessagePresenter($viewer, ConversationAbilities::for($viewer, $conversation)))
            ->present(new EloquentCollection([$message]));

        return response()->json([
            'message' => $presented['messages'][0],
            'users' => $presented['users'],
            ...$extra,
        ], $status);
    }

    /**
     * Un número entero positivo de la query (?antes=, ?despues=…), o null.
     */
    protected function positiveInt(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
