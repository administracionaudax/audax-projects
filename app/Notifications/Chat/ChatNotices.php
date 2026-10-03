<?php

namespace App\Notifications\Chat;

use App\Broadcasting\ChatNoticeThrottle;
use App\Broadcasting\ConversationViewers;
use App\Broadcasting\WebPushConfig;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Http\Resources\Chat\ChatUsers;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién recibe aviso de un mensaje nuevo del chat (SPEC §12 y §13, D-072). Se ejecuta en la cola
 * (SendChatNotices) justo después de publicar el mensaje:
 *
 * - mensaje directo → la otra persona,
 * - @todos → todos los participantes,
 * - <@ID> → la persona mencionada (gana a los anteriores),
 *
 * y nunca a: el autor, quien ya no participa, personas desactivadas o clientes, un colaborador
 * externo fuera de su alcance (ConversationPolicy, D-134), quien tiene la
 * conversación abierta en ese momento (ConversationViewers) ni quien ya recibió un aviso de esa
 * conversación en los últimos 5 minutos (ChatNoticeThrottle). Silenciar una conversación quita
 * los avisos de @todos y de los directos; una mención personal sigue llegando a la campana, pero
 * no al navegador. Los mensajes de sistema, borrados u ocultados no avisan.
 */
final class ChatNotices
{
    public const string DIRECT = 'direct';

    public const string EVERYONE = 'everyone';

    public const string MENTION = 'mention';

    public function __construct(
        private readonly ConversationViewers $viewers,
        private readonly ChatNoticeThrottle $throttle,
    ) {}

    /**
     * @return array<int, string> persona avisada → motivo (direct, everyone o mention)
     */
    public function forMessage(int $messageId): array
    {
        $message = Message::query()
            ->with(['conversation.project:id,name', 'author:id,name'])
            ->find($messageId);

        if ($message === null || $message->user_id === null || $message->author === null
            || $message->type === MessageType::System || $message->hidden_at !== null) {
            return [];
        }

        $conversation = $message->conversation;
        $participants = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->user_id)
            // Red de seguridad (D-134): nadie fuera del alcance de la conversación, aunque siga
            // como participante (un colaborador externo en una directa, un grupo o un proyecto ajeno).
            ->whereHas('user', fn (Builder $query) => $query->active()->internal()->withinConversationScope($conversation))
            ->with(['user' => fn ($query) => $query->select(['id', 'name', 'is_active'])->withExists('pushSubscriptions')])
            ->get()
            ->keyBy('user_id');

        $reasons = $this->reasons($message, $conversation->type, array_values(array_map('intval', $participants->keys()->all())));
        if ($reasons === []) {
            return [];
        }

        $viewing = $this->viewers->viewing($conversation->id, array_keys($reasons));
        $excerpt = ChatExcerpt::of($message, $this->mentionNames($message));
        $name = $conversation->type === ConversationType::Project ? $conversation->project?->name : $conversation->name;
        $pushEnabled = WebPushConfig::enabled();
        $sent = [];

        foreach ($reasons as $userId => $reason) {
            /** @var ConversationParticipant $participant */
            $participant = $participants->get($userId);

            if (($participant->muted && $reason !== self::MENTION)
                || in_array($userId, $viewing, true)
                || ! $this->throttle->attempt($userId, $conversation->id)) {
                continue;
            }

            $push = $pushEnabled && ! $participant->muted && (bool) $participant->user->getAttribute('push_subscriptions_exists');
            $arguments = [$conversation->id, $message->id, $name, $message->author->name, $excerpt, $push];

            $participant->user->notify(match ($reason) {
                self::DIRECT => new ChatDirectMessageNotification(...$arguments),
                self::EVERYONE => new ChatEveryoneNotification(...$arguments),
                default => new ChatMentionNotification(...$arguments),
            });

            $sent[$userId] = $reason;
        }

        return $sent;
    }

    /**
     * @param  list<int>  $participantIds  participantes activos que pueden recibir aviso (sin el autor)
     * @return array<int, string>
     */
    private function reasons(Message $message, ConversationType $type, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        if ($type === ConversationType::Direct) {
            return array_fill_keys($participantIds, self::DIRECT);
        }

        $mentions = MessageMention::query()->where('message_id', $message->id)->get(['user_id', 'everyone']);
        $reasons = $mentions->contains(fn (MessageMention $mention): bool => $mention->everyone)
            ? array_fill_keys($participantIds, self::EVERYONE)
            : [];

        foreach ($mentions as $mention) {
            if ($mention->user_id !== null && in_array($mention->user_id, $participantIds, true)) {
                $reasons[$mention->user_id] = self::MENTION;
            }
        }

        ksort($reasons);

        return $reasons;
    }

    /**
     * Nombres de las personas citadas con <@ID> que se resuelven (ChatUsers::mentionable), para
     * escribir @Nombre en el aviso.
     *
     * @return array<int, string>
     */
    private function mentionNames(Message $message): array
    {
        $ids = ChatUsers::mentionable([$message])[$message->id] ?? [];

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return User::query()->whereKey($ids)->pluck('name', 'id')->all();
    }
}
