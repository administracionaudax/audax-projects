<?php

namespace App\Domain\Chat;

use App\Models\Conversation;
use App\Models\ConversationParticipant;

/**
 * Entrar en una conversación (o volver a ella): lo anterior, también lo publicado mientras no se
 * estaba, cuenta como leído y solo avisa de lo nuevo (D-115). Nunca hacia atrás. La usan
 * ConversationDirectory y MessageWriter (quien escribe en un canal pasa a participar, D-270).
 */
final class Participants
{
    public static function join(Conversation $conversation, int $userId): ConversationParticipant
    {
        $participant = ConversationParticipant::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
        ]);

        if (! $participant->exists || $participant->left_at !== null) {
            $participant->fill(['joined_at' => $participant->exists ? $participant->joined_at : now(), 'left_at' => null]);
            $last = $conversation->messages()->withTrashed()->max('id');
            if ($last !== null && (int) $last > (int) ($participant->last_read_message_id ?? 0)) {
                $participant->last_read_message_id = (int) $last;
            }
            $participant->save();
            $conversation->forgetParticipants();
        }

        return $participant;
    }
}
