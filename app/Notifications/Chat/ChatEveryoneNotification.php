<?php

namespace App\Notifications\Chat;

/**
 * @todos en una conversación de proyecto o de grupo: a todos sus participantes salvo el autor y
 * quien la tenga silenciada.
 */
class ChatEveryoneNotification extends ChatMessageNotification
{
    public function kind(): string
    {
        return 'chat.everyone';
    }

    public function title(object $notifiable): string
    {
        return __('realtime.notifications.everyone', ['actor' => $this->actorName, 'conversation' => (string) $this->conversationName]);
    }

    public function icon(): ?string
    {
        return 'at-sign';
    }
}
