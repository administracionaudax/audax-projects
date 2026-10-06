<?php

namespace App\Http\Resources\Chat;

use App\Broadcasting\UnreadCounts;
use App\Domain\Chat\ConversationAccess;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Tarjeta «Menciones» de Inicio (SPEC §5.1 y §12): las menciones recientes a quien mira (personales
 * y @todos, de los últimos 14 días, en las conversaciones en las que participa hoy) y las
 * conversaciones con mensajes sin leer (sin las silenciadas), con el total de la navegación.
 *
 * Ocho consultas como mucho, tenga lo que tenga: los no leídos (UnreadCounts, dos), las menciones,
 * las conversaciones con sus proyectos, la otra persona de cada directa, las menciones que se
 * resuelven (ChatUsers::mentionable) y las personas. Lo
 * ocultado por un admin y lo borrado no aparecen; las propias tampoco.
 */
final class HomeChatSummary
{
    public const int MENTIONS = 5;

    public const int CONVERSATIONS = 5;

    public const int MENTION_DAYS = 14;

    public function __construct(private readonly UnreadCounts $counts) {}

    /**
     * @return array{unread_total: int, conversations: list<array{id: int, type: string, title: string, unread: int, url: string}>, mentions: list<array{id: int, conversation_id: int, conversation: string, author: string|null, excerpt: string, everyone: bool, unread: bool, created_at: string|null, url: string}>}
     */
    public function for(User $user): array
    {
        $counts = $this->counts->for($user);
        $unread = array_diff_key($counts['conversations'], array_flip($counts['muted']));
        arsort($unread);
        $unread = array_slice($unread, 0, self::CONVERSATIONS, true);

        $mentions = $this->mentions($user);
        $ids = array_values(array_unique([...array_keys($unread), ...$mentions->pluck('conversation_id')->map(fn (mixed $id): int => (int) $id)->all()]));

        if ($ids === []) {
            return ['unread_total' => $counts['total'], 'conversations' => [], 'mentions' => []];
        }

        $conversations = Conversation::query()
            ->whereKey($ids)
            ->with([
                'project' => fn (Relation $query) => $query->select(['id', 'code', 'name']),
                'client' => fn (Relation $query) => $query->select(['id', 'name']),
            ])
            ->get(['id', 'type', 'name', 'project_id', 'client_id'])
            ->keyBy('id');

        $directIds = $conversations->where('type', ConversationType::Direct)->modelKeys();
        $others = $directIds === [] ? collect() : ConversationParticipant::query()
            ->whereIn('conversation_id', $directIds)
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id', 'conversation_id');

        $mentioned = ChatUsers::mentionable($mentions);
        $userIds = [...$others->values()->all(), ...$mentions->pluck('user_id')->all(), ...array_merge(...array_values($mentioned))];
        $users = ChatUsers::load($userIds);

        $title = function (int $id) use ($conversations, $others, $users): string {
            /** @var Conversation $conversation */
            $conversation = $conversations->get($id);
            $otherId = $others->get($id);

            return ConversationPresenter::title(
                $conversation,
                $conversation->type === ConversationType::Project ? $conversation->project : null,
                $otherId === null ? null : ($users[(int) $otherId] ?? null),
            );
        };

        $rows = [];
        foreach ($unread as $id => $count) {
            $conversation = $conversations->get($id);

            if ($conversation !== null) {
                $rows[] = [
                    'id' => $id,
                    'type' => $conversation->type->value,
                    'title' => $title($id),
                    'unread' => $count,
                    'url' => route('chat.show', ['conversation' => $id], false),
                ];
            }
        }

        return [
            'unread_total' => $counts['total'],
            'conversations' => $rows,
            'mentions' => array_values($mentions
                ->filter(fn (Message $message): bool => $conversations->has($message->conversation_id))
                ->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'conversation' => $title($message->conversation_id),
                    'author' => $message->user_id === null ? null : ($users[$message->user_id] ?? null)?->name,
                    'excerpt' => MessagePreview::plain($message->body, ChatUsers::only($users, $mentioned[$message->id] ?? []), 120),
                    'everyone' => ! (bool) $message->getAttribute('personal'),
                    'unread' => (bool) $message->getAttribute('is_unread'),
                    'created_at' => $message->created_at?->toIso8601ZuluString(),
                    'url' => route('chat.show', ['conversation' => $message->conversation_id, 'mensaje' => $message->id], false),
                ])->all()),
        ];
    }

    /**
     * Las menciones más recientes (personales o @todos) de otras personas, en una consulta.
     *
     * @return EloquentCollection<int, Message>
     */
    private function mentions(User $user): EloquentCollection
    {
        $projectIds = $user->visibleProjectIds();
        $mentioned = fn (QueryBuilder $query) => $query->from('message_mentions as mm')
            ->whereColumn('mm.message_id', 'messages.id')
            ->where(fn (QueryBuilder $who) => $who->where('mm.user_id', $user->id)->orWhere('mm.everyone', true));

        return Message::query()
            ->select(['messages.id', 'messages.conversation_id', 'messages.user_id', 'messages.body', 'messages.created_at'])
            ->selectRaw('case when messages.id > coalesce(p.last_read_message_id, 0) then 1 else 0 end as is_unread')
            ->selectRaw('case when exists (select 1 from message_mentions as mp where mp.message_id = messages.id and mp.user_id = ?) then 1 else 0 end as personal', [$user->id])
            ->join('conversation_participants as p', function (JoinClause $join) use ($user): void {
                $join->on('p.conversation_id', '=', 'messages.conversation_id')
                    ->where('p.user_id', '=', $user->id)
                    ->whereNull('p.left_at');
            })
            // Un colaborador externo, solo en las conversaciones de su alcance (D-134, D-271),
            // aunque siga como participante de otra.
            ->when($projectIds !== null, fn (Builder $query) => $query->whereIn('messages.conversation_id', Conversation::query()
                ->select('conversations.id')
                ->where(fn (Builder $scope) => ConversationAccess::scope($scope, $user))))
            ->whereExists($mentioned)
            ->whereNotNull('messages.user_id')
            ->where('messages.user_id', '!=', $user->id)
            ->whereNull('messages.hidden_at')
            ->where('messages.created_at', '>=', now()->subDays(self::MENTION_DAYS))
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit(self::MENTIONS)
            ->get();
    }
}
