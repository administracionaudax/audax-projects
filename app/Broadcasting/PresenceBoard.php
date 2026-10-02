<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Presencia SIN tiempo real (consultas periódicas): cada pestaña abierta manda un latido por
 * minuto con su estado («en línea» o «ausente» tras 5 minutos sin actividad) y recibe el de los
 * demás. Con Reverb, la presencia sale del canal presence «online» y esto no se usa.
 *
 * - En línea: algún latido «en línea» en los últimos 90 s (si una pestaña está activa y otra
 *   ausente, gana la activa).
 * - Ausente: hay latidos, pero ninguno «en línea» reciente.
 * - Desconectado: sin latidos en 150 s (caduca solo en la caché).
 */
final class PresenceBoard
{
    public const int ONLINE_SECONDS = 90;

    public const int TTL_SECONDS = 150;

    public const array STATUSES = ['online', 'away'];

    public function beat(int $userId, string $status): void
    {
        $now = now()->getTimestamp();
        $previous = Cache::get(self::key($userId));
        $onlineAt = $status === 'online'
            ? $now
            : (is_array($previous) && is_int($previous['online_at'] ?? null) ? $previous['online_at'] : null);

        Cache::put(self::key($userId), ['beat_at' => $now, 'online_at' => $onlineAt], self::TTL_SECONDS);
    }

    /**
     * Estado de las personas internas activas que tienen alguna pestaña abierta.
     *
     * @return array<int, 'online'|'away'>
     */
    public function statuses(): array
    {
        /** @var list<int> $ids */
        $ids = Cache::remember('presence.user-ids', 60, fn (): array => User::query()->active()->internal()
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        if ($ids === []) {
            return [];
        }

        $values = Cache::many(array_map(self::key(...), $ids));
        $threshold = now()->getTimestamp() - self::ONLINE_SECONDS;
        $statuses = [];

        foreach ($ids as $id) {
            $value = $values[self::key($id)] ?? null;
            if (! is_array($value)) {
                continue;
            }

            $onlineAt = $value['online_at'] ?? null;
            $statuses[$id] = is_int($onlineAt) && $onlineAt >= $threshold ? 'online' : 'away';
        }

        ksort($statuses);

        return $statuses;
    }

    private static function key(int $userId): string
    {
        return "presence.{$userId}";
    }
}
