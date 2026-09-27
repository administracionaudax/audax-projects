<?php

namespace App\Http\Resources\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Lista de conversaciones de /chat (contrato: resources/js/types/chat.ts, ChatConversationItem):
 * las que tiene quien mira (ConversationDirectory::forUser), de la última actividad a la más
 * antigua, con su nombre, la vista previa del último mensaje, la hora y los no leídos.
 *
 * Siempre 7 consultas, tenga las conversaciones que tenga: conversaciones (con el id de su último
 * mensaje y el recuento de participantes como subconsultas), proyectos, lo suyo (silenciada y
 * leído), la otra persona de cada directa, los últimos mensajes, las personas y los no leídos
 * (una sola consulta agregada).
 */
final class ConversationList
{
    /** Tope de seguridad: con 100 personas nadie llega (las directas son 99 como mucho). */
    public const int MAX = 500;

    public function __construct(private readonly ConversationDirectory $directory) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user): array
    {
        $conversations = $this->directory->forUser($user)
            ->reorder()
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id')
            ->with(['project' => fn (Relation $query) => $query->select(['id', 'code', 'name', 'color', 'status'])])
            ->withCount('activeParticipants')
            ->addSelect(['last_visible_message_id' => Message::query()
                ->select('id')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->orderByDesc('id')
                ->limit(1)])
            ->limit(self::MAX)
            ->get();

        if ($conversations->isEmpty()) {
            return [];
        }

        $ids = array_values($conversations->map(fn (Conversation $conversation): int => $conversation->id)->all());
        $mine = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', $ids)
            ->get(['id', 'conversation_id', 'user_id', 'muted', 'last_read_message_id'])
            ->keyBy('conversation_id');

        $directIds = $conversations->where('type', ConversationType::Direct)->modelKeys();
        $others = $directIds === [] ? collect() : ConversationParticipant::query()
            ->whereIn('conversation_id', $directIds)
            ->where('user_id', '!=', $user->id)
            ->get(['id', 'conversation_id', 'user_id'])
            ->keyBy('conversation_id');

        $lastIds = array_values(array_filter($conversations->pluck('last_visible_message_id')->all()));
        $last = $lastIds === [] ? collect() : Message::query()
            ->whereKey($lastIds)
            ->get(['id', 'conversation_id', 'user_id', 'type', 'body', 'system_key', 'system_payload', 'hidden_at', 'created_at'])
            ->keyBy('conversation_id');

        $userIds = [...$others->pluck('user_id')->all(), ...$last->pluck('user_id')->all()];
        foreach ($last as $message) {
            array_push($userIds, ...MessagePreview::mentionIds($message->body));
        }
        $users = ChatUsers::load($userIds);
        $unread = $this->directory->unreadCounts($user, $ids);

        return array_values($conversations->map(function (Conversation $conversation) use ($user, $mine, $others, $last, $users, $unread): array {
            $project = $conversation->type === ConversationType::Project ? $conversation->project : null;
            $otherId = $others->get($conversation->id)?->user_id;
            $other = $otherId === null ? null : ($users[$otherId] ?? null);
            /** @var ConversationParticipant|null $participant */
            $participant = $mine->get($conversation->id);
            /** @var Message|null $message */
            $message = $last->get($conversation->id);

            return [
                'id' => $conversation->id,
                'type' => $conversation->type->value,
                'title' => ConversationPresenter::title($conversation, $project, $other),
                'subtitle' => $project?->code,
                'project' => $project === null ? null : ConversationPresenter::project($project),
                'other_user' => $other === null ? null : ChatUsers::present($other),
                'members_count' => (int) ($conversation->getAttribute('active_participants_count') ?? 0),
                'muted' => $participant !== null && $participant->muted,
                'unread' => $unread[$conversation->id] ?? 0,
                'read_only' => $project !== null && ! $project->acceptsTime(),
                'last_message' => $message === null ? null : $this->lastMessage($message, $user, $users),
                'last_activity_at' => ($conversation->last_message_at ?? $conversation->created_at)?->toIso8601ZuluString(),
            ];
        })->all());
    }

    /**
     * Vista previa del último mensaje: texto plano (sin markdown), autor y tipo. Lo ocultado por
     * un admin no se enseña en la lista, ni siquiera a quien modera.
     *
     * @param  array<int, User>  $users
     * @return array<string, mixed>
     */
    private function lastMessage(Message $message, User $viewer, array $users): array
    {
        $kind = $message->hidden_at !== null ? 'hidden' : $message->type->value;
        $system = $message->type === MessageType::System;

        return [
            'id' => $message->id,
            'kind' => $kind,
            'author' => $message->user_id === null ? null : ($users[$message->user_id] ?? null)?->name,
            'is_mine' => $message->user_id === $viewer->id,
            'preview' => $kind === 'hidden' || $system ? '' : MessagePreview::plain($message->body, $users),
            'system' => $system && $kind !== 'hidden' ? ['key' => (string) $message->system_key, 'payload' => (object) ($message->system_payload ?? [])] : null,
            'created_at' => $message->created_at?->toIso8601ZuluString(),
        ];
    }
}
