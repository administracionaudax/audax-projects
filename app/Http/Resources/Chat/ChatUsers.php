<?php

namespace App\Http\Resources\Chat;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Personas del chat (autores, participantes, mencionadas, quien reacciona): se cargan todas de una
 * vez por ids (una consulta por página, sin N+1). Contrato: resources/js/types/chat.ts (ChatUser).
 * Las desactivadas siguen en el histórico, marcadas como inactivas (SPEC §12).
 *
 * Las menciones <@ID> del cuerpo las escribe quien publica: solo se resuelven (nombre y avatar)
 * las de quien participa o ha participado en la conversación o tiene su fila en message_mentions
 * del mensaje (mentionable). El resto se pinta como «Persona desconocida»: escribir un <@ID> a
 * mano no revela quién es nadie (D-134). Es la misma regla para todos.
 */
final class ChatUsers
{
    /**
     * @param  iterable<int|null>  $ids
     * @return array<int, User>
     */
    public static function load(iterable $ids): array
    {
        $unique = [];

        foreach ($ids as $id) {
            if ($id !== null && $id > 0) {
                $unique[$id] = true;
            }
        }

        if ($unique === []) {
            return [];
        }

        return User::query()
            ->whereKey(array_keys($unique))
            ->get(['id', 'name', 'avatar_path', 'is_active'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Ids de las menciones <@ID> que se resuelven en cada mensaje: las de quien participa o ha
     * participado (con left_at) en su conversación y las que tienen fila en message_mentions.
     * Una consulta como mucho, sin importar cuántos mensajes haya.
     *
     * @param  iterable<Message|null>  $messages  con id, conversation_id y body
     * @return array<int, list<int>> id del mensaje => ids que se resuelven (solo mensajes con alguno)
     */
    public static function mentionable(iterable $messages): array
    {
        /** @var array<int, array{conversation: int, ids: list<int>}> $parsed */
        $parsed = [];
        $conversationIds = [];
        $userIds = [];

        foreach ($messages as $message) {
            if ($message === null) {
                continue;
            }

            $ids = MessagePreview::mentionIds($message->body);

            if ($ids !== []) {
                $parsed[$message->id] = ['conversation' => $message->conversation_id, 'ids' => $ids];
                $conversationIds[$message->conversation_id] = true;
                array_push($userIds, ...$ids);
            }
        }

        if ($parsed === []) {
            return [];
        }

        $userIds = array_values(array_unique($userIds));
        $participants = DB::table('conversation_participants')
            ->selectRaw("'c' as kind, conversation_id as owner_id, user_id")
            ->whereIn('conversation_id', array_keys($conversationIds))
            ->whereIn('user_id', $userIds);
        $rows = DB::table('message_mentions')
            ->selectRaw("'m' as kind, message_id as owner_id, user_id")
            ->whereIn('message_id', array_keys($parsed))
            ->whereIn('user_id', $userIds)
            ->unionAll($participants)
            ->get();

        $known = [];
        foreach ($rows as $row) {
            $known[$row->kind.$row->owner_id.':'.$row->user_id] = true;
        }

        $result = [];

        foreach ($parsed as $messageId => $message) {
            $allowed = array_values(array_filter($message['ids'], fn (int $id): bool => isset($known['c'.$message['conversation'].':'.$id]) || isset($known['m'.$messageId.':'.$id])));

            if ($allowed !== []) {
                $result[$messageId] = $allowed;
            }
        }

        return $result;
    }

    /**
     * Las personas de $users con esos ids (para resolver las menciones de un solo mensaje).
     *
     * @param  array<int, User>  $users
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    public static function only(array $users, array $ids): array
    {
        return array_intersect_key($users, array_flip($ids));
    }

    /**
     * @return array{id: int, name: string, avatar: string|null, is_active: bool}
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar_url,
            'is_active' => $user->is_active,
        ];
    }

    /**
     * @param  array<int, User>  $users
     * @return list<array{id: int, name: string, avatar: string|null, is_active: bool}>
     */
    public static function list(array $users): array
    {
        return array_values(array_map(self::present(...), $users));
    }
}
