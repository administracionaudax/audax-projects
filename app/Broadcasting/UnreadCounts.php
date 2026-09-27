<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Mensajes sin leer de una persona (SPEC §12): por conversación y en total, en UNA consulta.
 *
 * Sin leer = mensajes posteriores a su last_read_message_id que no son suyos, ni borrados, ni
 * ocultados por moderación, en las conversaciones en las que participa hoy (left_at nulo). Las
 * silenciadas tienen su número, pero no suman al total de la navegación.
 */
final class UnreadCounts
{
    /**
     * @return array{total: int, conversations: array<int, int>, muted: list<int>}
     */
    public function for(User $user): array
    {
        $rows = DB::table('conversation_participants as p')
            ->join('messages as m', function (JoinClause $join) use ($user): void {
                $join->on('m.conversation_id', '=', 'p.conversation_id')
                    ->on('m.id', '>', DB::raw('COALESCE(p.last_read_message_id, 0)'))
                    ->whereNull('m.deleted_at')
                    ->whereNull('m.hidden_at')
                    ->where(fn ($query) => $query->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id));
            })
            ->where('p.user_id', $user->id)
            ->whereNull('p.left_at')
            ->groupBy('p.conversation_id', 'p.muted')
            ->select(['p.conversation_id', 'p.muted', DB::raw('COUNT(m.id) as unread')])
            ->get();

        $conversations = [];
        $muted = [];
        $total = 0;

        foreach ($rows as $row) {
            $id = (int) $row->conversation_id;
            $count = (int) $row->unread;
            $conversations[$id] = $count;

            if ((bool) $row->muted) {
                $muted[] = $id;
            } else {
                $total += $count;
            }
        }

        ksort($conversations);
        sort($muted);

        return ['total' => $total, 'conversations' => $conversations, 'muted' => $muted];
    }
}
