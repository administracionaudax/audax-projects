<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\Cache;

/**
 * Agrupación de los avisos del chat: como mucho UN aviso por conversación cada 5 minutos por
 * persona (en la app y en el navegador). Cuando la persona lee la conversación o la abre, se
 * vuelve a empezar: el siguiente mensaje sí avisa.
 */
final class ChatNoticeThrottle
{
    public const int WINDOW_SECONDS = 300;

    /**
     * Reserva el aviso (atómico: Cache::add). false si ya se avisó en los últimos 5 minutos.
     */
    public function attempt(int $userId, int $conversationId): bool
    {
        return Cache::add(self::key($userId, $conversationId), 1, self::WINDOW_SECONDS);
    }

    public function reset(int $userId, int $conversationId): void
    {
        Cache::forget(self::key($userId, $conversationId));
    }

    private static function key(int $userId, int $conversationId): string
    {
        return "chat.notice.{$userId}.{$conversationId}";
    }
}
