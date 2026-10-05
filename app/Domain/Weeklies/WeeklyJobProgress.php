<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyJobState;
use App\Events\Weeklies\WeeklyGenerationUpdated;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Cache;

/**
 * Progreso de los trabajos largos de una semana (informe y audio, D-190): el estado va en la semana
 * (report_state y audio_state) y el detalle (paso, hechos y total) en caché 15 minutos, para que
 * `weeklies.report.status` lo devuelva sin Reverb. Cada cambio se emite por Reverb.
 *
 * Un trabajo «en cola» o «generando» cuya semana lleva más de AiQueue::TIMEOUT + 2 minutos sin
 * cambiar se da por atascado (p. ej. se reinició Horizon): se puede volver a pedir.
 */
final class WeeklyJobProgress
{
    public const string REPORT = 'report';

    public const string AUDIO = 'audio';

    public const int STUCK_SECONDS = 720;

    /**
     * @param  self::REPORT|self::AUDIO  $kind
     */
    public static function update(WeeklyCycle $cycle, string $kind, WeeklyJobState $state, ?string $step = null, int $done = 0, int $total = 0, ?string $error = null): void
    {
        if ($state->isBusy()) {
            Cache::put(self::key($cycle->id, $kind), ['step' => $step, 'done' => $done, 'total' => $total], 900);
        } else {
            Cache::forget(self::key($cycle->id, $kind));
        }

        event(new WeeklyGenerationUpdated($cycle->id, $kind, $state->value, $step, $done, $total, $error));
    }

    /**
     * @param  self::REPORT|self::AUDIO  $kind
     * @return array{step: string|null, done: int, total: int}|null
     */
    public static function detail(int $cycleId, string $kind): ?array
    {
        /** @var array{step: string|null, done: int, total: int}|null */
        return Cache::get(self::key($cycleId, $kind));
    }

    /** ¿Hay un trabajo de este tipo en marcha (y no atascado)? */
    public static function isRunning(WeeklyCycle $cycle, string $kind): bool
    {
        $state = $kind === self::REPORT ? $cycle->report_state : $cycle->audio_state;

        return $state !== null && $state->isBusy()
            && ($cycle->updated_at === null || $cycle->updated_at->greaterThan(now()->subSeconds(self::STUCK_SECONDS)));
    }

    private static function key(int $cycleId, string $kind): string
    {
        return "weekly-progress:{$cycleId}:{$kind}";
    }
}
