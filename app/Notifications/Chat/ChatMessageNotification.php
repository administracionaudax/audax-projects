<?php

namespace App\Notifications\Chat;

use App\Broadcasting\SendsWebPush;
use App\Broadcasting\WebPushChannel;
use App\Broadcasting\WebPushMessage;
use App\Notifications\AppNotification;

/**
 * Aviso de un mensaje del chat (SPEC §12 y §13): en la campana y, si la persona lo ha activado en
 * algún navegador y no ha silenciado la conversación, también como aviso del navegador (D-072).
 * Guarda datos planos (ids y textos), no modelos: la cola no falla si el mensaje se borra antes.
 * El enlace es estable (/tiempo-real/conversaciones/{id}/abrir) y lleva a la conversación en la
 * pantalla del chat que haya al pulsarlo.
 */
abstract class ChatMessageNotification extends AppNotification implements SendsWebPush
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly ?string $conversationName,
        public readonly string $actorName,
        public readonly ?string $excerpt,
        public readonly bool $push = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->push ? ['database', WebPushChannel::class] : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [...parent::viaQueues(), WebPushChannel::class => 'default'];
    }

    public function body(object $notifiable): ?string
    {
        return $this->excerpt;
    }

    public function url(object $notifiable): ?string
    {
        return self::conversationUrl($this->conversationId, $this->messageId);
    }

    public function toWebPush(object $notifiable): ?WebPushMessage
    {
        return new WebPushMessage(
            $this->title($notifiable),
            $this->excerpt,
            (string) $this->url($notifiable),
            'conversation-'.$this->conversationId,
        );
    }

    public static function conversationUrl(int $conversationId, ?int $messageId = null): string
    {
        return route('realtime.conversations.open', array_filter([
            'conversation' => $conversationId,
            'mensaje' => $messageId,
        ], fn (?int $value): bool => $value !== null), false);
    }
}
