<?php

namespace App\Notifications\Chat;

/**
 * Te han mencionado (<@ID>) en una conversación de proyecto o de grupo. Llega aunque la
 * conversación esté silenciada (en la campana; en el navegador, no).
 */
class ChatMentionNotification extends ChatMessageNotification
{
    public function kind(): string
    {
        return 'chat.mention';
    }

    public function title(object $notifiable): string
    {
        return __('realtime.notifications.mention', ['actor' => $this->actorName, 'conversation' => (string) $this->conversationName]);
    }

    public function icon(): ?string
    {
        return 'at-sign';
    }
}
