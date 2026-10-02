<?php

namespace App\Broadcasting;

use App\Models\Conversation;
use App\Models\ConversationParticipant;

/**
 * «Leído por» (SPEC §12): hasta qué mensaje ha leído cada participante activo. El cliente cruza
 * ese dato con cada mensaje (leído si last_read_message_id ≥ id) y lo mantiene al día con el
 * evento conversation.read. Dos consultas, sin importar cuántos participantes haya.
 */
final class ReadReceipts
{
    /**
     * @return list<array{id: int, name: string, avatar: string|null, is_active: bool, last_read_message_id: int|null}>
     */
    public function for(Conversation $conversation): array
    {
        $participants = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->with('user:id,name,avatar_path,is_active')
            ->orderBy('id')
            ->get();

        $receipts = [];
        foreach ($participants as $participant) {
            $receipts[] = [
                'id' => $participant->user_id,
                'name' => $participant->user->name,
                'avatar' => $participant->user->avatar_url,
                'is_active' => $participant->user->is_active,
                'last_read_message_id' => $participant->last_read_message_id === null ? null : (int) $participant->last_read_message_id,
            ];
        }

        return $receipts;
    }
}
