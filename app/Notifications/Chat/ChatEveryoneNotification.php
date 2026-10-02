<?php

namespace App\Notifications\Chat;

/**
 * @todos en una conversación de proyecto o de grupo: a todos sus participantes salvo el autor y
 * quien la tenga silenciada. Es una mención más: su tipo es chat.mention (el de la preferencia
 * por canal que llega en la Fase 7); el título dice que es para todos.
 */
class ChatEveryoneNotification extends ChatMessageNotification
{
    public function kind(): string
    {
        return 'chat.mention';
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
