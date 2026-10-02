<?php

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real (D-068): hay un mensaje nuevo en la conversación. Va por la cola (ShouldBroadcast)
 * al canal privado conversation.{id}, que solo pueden escuchar quienes ven la conversación.
 *
 * El contenido NO viaja: solo ids y tipo. El cliente pide el mensaje por su ruta, que vuelve a
 * comprobar los permisos (y así un cuerpo de 10.000 caracteres nunca pasa del límite de Reverb).
 * Si la cola no responde, el mensaje ya está guardado: el fallo se registra y no se propaga.
 */
final class BroadcastMessagePosted implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    /** Sale solo cuando la transacción que guarda el mensaje se ha confirmado. */
    public bool $afterCommit = true;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $type,
        public readonly ?int $userId,
        public readonly ?int $parentId,
        public readonly ?string $createdAt,
    ) {}

    public static function fromMessage(Message $message): self
    {
        return new self(
            $message->conversation_id,
            $message->id,
            $message->type->value,
            $message->user_id,
            $message->parent_id,
            $message->created_at?->toIso8601ZuluString(),
        );
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
        return 'message.posted';
    }

    /**
     * @return array{conversation_id: int, message_id: int, type: string, user_id: int|null, parent_id: int|null, created_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'type' => $this->type,
            'user_id' => $this->userId,
            'parent_id' => $this->parentId,
            'created_at' => $this->createdAt,
        ];
    }
}
