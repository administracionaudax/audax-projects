<?php

namespace App\Listeners\Chat;

use App\Domain\Chat\Mentions;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Events\Chat\MessagePosted;
use App\Models\Conversation;
use App\Notifications\Chat\ChatNotices;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Avisos de un mensaje nuevo (menciones, @todos y directos): por la cola y cuando el mensaje ya
 * está guardado, para no alargar la petición de quien escribe. Las reglas, en ChatNotices.
 * Solo se encola si el mensaje PUEDE avisar a alguien (tiene menciones o es de una conversación
 * directa): la mayoría de los mensajes de un proyecto no generan trabajo en la cola.
 */
final class SendChatNotices implements ShouldQueueAfterCommit
{
    public function __construct(private readonly ChatNotices $notices) {}

    public function shouldQueue(MessagePosted $event): bool
    {
        $message = $event->message;

        if ($message->user_id === null || $message->type === MessageType::System) {
            return false;
        }

        $mentions = Mentions::parse((string) $message->body);

        return $mentions['users'] !== [] || $mentions['everyone']
            || Conversation::query()->whereKey($message->conversation_id)->where('type', ConversationType::Direct)->exists();
    }

    public function handle(MessagePosted $event): void
    {
        $this->notices->forMessage($event->message->id);
    }
}
