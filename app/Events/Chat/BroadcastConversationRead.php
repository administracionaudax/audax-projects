<?php

namespace App\Events\Chat;

use App\Models\ConversationParticipant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real (D-068): alguien ha leído hasta un mensaje. Va a la conversación («leído por») y al
 * canal personal de quien lee, para que sus otras pestañas y dispositivos pongan a cero el
 * contador de no leídos.
 */
final class BroadcastConversationRead implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastReadMessageId,
    ) {}

    public static function fromParticipant(ConversationParticipant $participant): self
    {
        return new self($participant->conversation_id, $participant->user_id, (int) $participant->last_read_message_id);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    /**
     * @return array{conversation_id: int, user_id: int, last_read_message_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
