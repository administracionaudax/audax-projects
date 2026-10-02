<?php

namespace App\Broadcasting;

use App\Domain\Chat\ConversationDirectory;
use App\Models\ConversationParticipant;
use App\Models\User;

/**
 * Mensajes sin leer de una persona (SPEC §12): por conversación y en total, para los contadores en
 * vivo (useUnreadCounter). Qué cuenta como no leído lo decide una sola regla, la del chat
 * (ConversationDirectory::unreadCounts: de otras personas o de sistema, posteriores a lo leído, ni
 * borrados ni ocultados, en las conversaciones en las que participa hoy). Las silenciadas tienen su
 * número, pero no suman al total de la navegación; se devuelven todas (tengan o no mensajes
 * pendientes) para que el contador en vivo sepa cuáles no suman. Dos consultas.
 */
final class UnreadCounts
{
    public function __construct(private readonly ConversationDirectory $directory) {}

    /**
     * @return array{total: int, conversations: array<int, int>, muted: list<int>}
     */
    public function for(User $user): array
    {
        $conversations = $this->directory->unreadCounts($user);

        /** @var list<int> $muted */
        $muted = array_values(ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->where('muted', true)
            ->orderBy('conversation_id')
            ->pluck('conversation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        $total = 0;

        foreach ($conversations as $id => $count) {
            if (! in_array($id, $muted, true)) {
                $total += $count;
            }
        }

        ksort($conversations);

        return ['total' => $total, 'conversations' => $conversations, 'muted' => $muted];
    }
}
