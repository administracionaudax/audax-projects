<?php

namespace App\Notifications\Chat;

use App\Broadcasting\WebPushChannel;
use App\Broadcasting\WebPushMessage;
use App\Models\Message;
use App\Notifications\AppNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aviso de un mensaje del chat (SPEC §12 y §13): por los canales que la persona quiere para su
 * evento (chat.direct o chat.mention, D-073): por defecto la campana y, si lo ha activado en algún
 * navegador y no ha silenciado la conversación, también el aviso del navegador (D-072).
 * Guarda datos planos (ids y textos), no modelos: la cola no falla si el mensaje se borra antes.
 * El enlace es estable (/tiempo-real/conversaciones/{id}/abrir) y lleva a la conversación en la
 * pantalla del chat que haya al pulsarlo. Si el mensaje se oculta o se borra antes de que la cola
 * lo envíe, no se envía (y los ya guardados pierden el extracto: ChatNotificationExcerpts, D-115).
 */
abstract class ChatMessageNotification extends AppNotification
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
     * Los canales que la persona quiere para este evento (NotificationPreferences, D-073), menos
     * el aviso del navegador cuando ChatNotices no lo permite ($push: conversación silenciada o sin
     * navegadores suscritos, D-072). Quién recibe aviso, con la conversación abierta o dentro de
     * los 5 minutos de agrupación, lo decide antes ChatNotices.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = parent::via($notifiable);

        if ($this->push) {
            return $channels;
        }

        return array_values(array_filter($channels, fn (string $channel): bool => $channel !== WebPushChannel::class));
    }

    /**
     * No, si cuando la cola envía el aviso el mensaje ya se ha borrado u ocultado (una consulta).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! Message::withTrashed()
            ->whereKey($this->messageId)
            ->where(fn (Builder $query) => $query->whereNotNull('deleted_at')->orWhereNotNull('hidden_at'))
            ->exists();
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
