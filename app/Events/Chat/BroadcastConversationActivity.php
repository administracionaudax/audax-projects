<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real (D-068): mensaje nuevo en una conversación, avisado en el canal PERSONAL de cada
 * participante (salvo el autor) para los contadores de no leídos en vivo: así nadie tiene que
 * suscribirse a todas sus conversaciones. Solo ids; nunca llega a quien no participa.
 * Un único envío a Reverb por cada 100 destinatarios (el broadcaster los agrupa).
 */
final class BroadcastConversationActivity implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly ?int $authorId,
        public readonly array $recipientIds,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id): PrivateChannel => new PrivateChannel('App.Models.User.'.$id), $this->recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.activity';
    }

    /**
     * @return array{conversation_id: int, message_id: int, user_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'user_id' => $this->authorId,
        ];
    }
}
