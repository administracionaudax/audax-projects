<?php

namespace App\Domain\Weeklies\Ai;

use App\Domain\Weeklies\WeeklyCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Límites diarios de IA por persona (D-222): cuántas peticiones a Gemini puede encolar cada persona al
 * día (de 0:00 a 24:00 en Madrid) en el asistente, los resúmenes con IA y las tareas sugeridas.
 * Configurables en `services.gemini.daily_limits` (0 = sin límite). El informe semanal, su audio y la
 * satisfacción del cierre no cuentan: los pide quien gestiona y van en la cola prioritaria.
 *
 * Solo se cuenta lo que de verdad se encola: pedir un resumen que ya se está generando no gasta.
 */
final class AiDailyLimits
{
    public const string ASSISTANT = 'assistant';

    public const string SUMMARIES = 'summaries';

    public const string SUGGESTED_TASKS = 'suggested_tasks';

    public function limit(string $bucket): int
    {
        return max(0, (int) config("services.gemini.daily_limits.{$bucket}", 0));
    }

    public function used(User $user, string $bucket): int
    {
        return (int) Cache::get($this->key($user, $bucket), 0);
    }

    /**
     * @throws AiDailyLimitReached si ya ha llegado al límite de hoy
     */
    public function ensureAvailable(User $user, string $bucket): void
    {
        $limit = $this->limit($bucket);

        if ($limit > 0 && $this->used($user, $bucket) >= $limit) {
            throw new AiDailyLimitReached($bucket, $limit);
        }
    }

    /**
     * Comprueba el límite y cuenta una petición.
     *
     * @throws AiDailyLimitReached
     */
    public function consume(User $user, string $bucket): void
    {
        $this->ensureAvailable($user, $bucket);

        $key = $this->key($user, $bucket);
        Cache::add($key, 0, $this->ttl());
        Cache::increment($key);
    }

    private function key(User $user, string $bucket): string
    {
        return "ai-daily:{$bucket}:{$user->id}:".CarbonImmutable::now(WeeklyCalendar::TIMEZONE)->toDateString();
    }

    /** Hasta el final del día de Madrid, más una hora de margen. */
    private function ttl(): int
    {
        $now = CarbonImmutable::now(WeeklyCalendar::TIMEZONE);

        return (int) $now->diffInSeconds($now->endOfDay(), true) + 3600;
    }
}
