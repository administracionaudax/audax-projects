<?php

namespace App\Notifications\Chat;

/**
 * Mensaje directo nuevo (SPEC §13): a la otra persona, si no tiene la conversación abierta ni la
 * ha silenciado.
 */
class ChatDirectMessageNotification extends ChatMessageNotification
{
    public function kind(): string
    {
        return 'chat.direct';
    }

    public function title(object $notifiable): string
    {
        return __('realtime.notifications.direct', ['actor' => $this->actorName]);
    }

    public function icon(): ?string
    {
        return 'message-square';
    }
}
