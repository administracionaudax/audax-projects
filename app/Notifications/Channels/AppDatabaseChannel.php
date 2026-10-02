<?php

namespace App\Notifications\Channels;

use App\Notifications\Chat\ChatMessageNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * El canal `database` de siempre (la campana) que, en los avisos del chat, guarda además el id del
 * mensaje en su columna (notifications.chat_message_id). Así, al ocultar o borrar el mensaje,
 * ChatNotificationExcerpts vacía su extracto con una consulta simple (D-115).
 */
final class AppDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof ChatMessageNotification) {
            $payload['chat_message_id'] = $notification->messageId;
        }

        return $payload;
    }
}
