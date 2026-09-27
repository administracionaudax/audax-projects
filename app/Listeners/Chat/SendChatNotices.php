<?php

namespace App\Listeners\Chat;

use App\Events\Chat\MessagePosted;
use App\Notifications\Chat\ChatNotices;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Avisos de un mensaje nuevo (menciones, @todos y directos): por la cola y cuando el mensaje ya
 * está guardado, para no alargar la petición de quien escribe. Las reglas, en ChatNotices.
 */
final class SendChatNotices implements ShouldQueueAfterCommit
{
    public function __construct(private readonly ChatNotices $notices) {}

    public function handle(MessagePosted $event): void
    {
        $this->notices->forMessage($event->message->id);
    }
}
