<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\Cache;

/**
 * Quién tiene una conversación abierta (D-072: no se avisa a quien ya la está viendo). Es un
 * «visto hace menos de un minuto» ligero en la caché: la pantalla de la conversación, mientras
 * está visible, lo renueva cada 30 s (useConversationChannel) y lo borra al cerrarse u ocultarse.
 * No toca la base de datos ni depende de que haya tiempo real.
 */
final class ConversationViewers
{
    /** Vale un minuto sin renovarse: si la pestaña se cierra de golpe, caduca solo. */
    public const int TTL_SECONDS = 60;

    public function touch(int $conversationId, int $userId): void
    {
        Cache::put(self::key($conversationId, $userId), 1, self::TTL_SECONDS);
    }

    public function forget(int $conversationId, int $userId): void
    {
        Cache::forget(self::key($conversationId, $userId));
    }

    public function isViewing(int $conversationId, int $userId): bool
    {
        return Cache::has(self::key($conversationId, $userId));
    }

    /**
     * De esas personas, las que tienen la conversación abierta (una sola lectura de la caché).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function viewing(int $conversationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $keys = [];
        foreach ($userIds as $userId) {
            $keys[self::key($conversationId, $userId)] = $userId;
        }

        $values = Cache::many(array_keys($keys));

        return array_map(
            fn (string $key): int => $keys[$key],
            array_keys(array_filter($values, fn (mixed $value): bool => $value !== null)),
        );
    }

    private static function key(int $conversationId, int $userId): string
    {
        return "chat.viewing.{$conversationId}.{$userId}";
    }
}
