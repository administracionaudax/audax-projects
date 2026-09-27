<?php

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real (D-068): un mensaje ha cambiado (editado, borrado, ocultado o visible de nuevo,
 * fijado o desfijado, reacciones…). Solo ids y una pista de qué cambió: el cliente vuelve a pedir
 * el mensaje (un mensaje ocultado nunca viaja con su texto).
 */
final class BroadcastMessageUpdated implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public const array CHANGES = ['edited', 'deleted', 'hidden', 'unhidden', 'pinned', 'unpinned', 'updated'];

    public bool $afterCommit = true;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $change,
    ) {}

    /**
     * La pista sale de lo que acaba de guardar MessageWriter (wasChanged): por eso el oyente que
     * lo emite es síncrono. Las reacciones no tocan el mensaje: quedan como «updated».
     */
    public static function fromMessage(Message $message): self
    {
        $change = match (true) {
            $message->trashed() => 'deleted',
            $message->wasChanged('hidden_at') => $message->hidden_at !== null ? 'hidden' : 'unhidden',
            $message->wasChanged('pinned_at') => $message->pinned_at !== null ? 'pinned' : 'unpinned',
            $message->wasChanged(['body', 'edited_at']) => 'edited',
            default => 'updated',
        };

        return new self($message->conversation_id, $message->id, $change);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /**
     * @return array{conversation_id: int, message_id: int, change: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'change' => $this->change,
        ];
    }
}
