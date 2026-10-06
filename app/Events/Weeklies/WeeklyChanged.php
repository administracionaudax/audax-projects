<?php

namespace App\Events\Weeklies;

use Closure;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo ha cambiado en una semana de la Weekly (10.9b, D-229; el tiempo real de WeeklySync, que
 * escuchaba `week_cycles`, `weekly_submissions` y los borradores con Supabase Realtime,
 * `ws:App.tsx:922-980`): un envío, una exención o «Estoy fuera», el plazo, el cierre, el informe o
 * la satisfacción. Va por el canal privado `weeklies` (quien usa la Weekly) como `weekly.changed`,
 * sin contenido: el resumen, el histórico y la página del informe vuelven a pedir sus datos, con
 * sus permisos. Sale al terminar la transacción y, si Reverb no está, no rompe la petición
 * (ShouldRescue): la página se pone al día al volver a ella (D-184).
 *
 * La importación y los datos de ejemplo lo silencian (`muted`): no hay nadie mirando.
 */
final class WeeklyChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    private static bool $muted = false;

    /**
     * @param  'submission'|'exemption'|'cycle'|'report'|'satisfaction'  $reason
     */
    public function __construct(
        public readonly ?int $cycleId,
        public readonly string $reason,
    ) {}

    /** Lo dispara salvo que esté silenciado. */
    public static function notify(?int $cycleId, string $reason): void
    {
        if (! self::$muted) {
            self::dispatch($cycleId, $reason);
        }
    }

    /**
     * Ejecuta $callback sin avisos (importación y datos de ejemplo).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function muted(Closure $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('weeklies')];
    }

    public function broadcastAs(): string
    {
        return 'weekly.changed';
    }

    /**
     * @return array{cycle_id: int|null, reason: string}
     */
    public function broadcastWith(): array
    {
        return ['cycle_id' => $this->cycleId, 'reason' => $this->reason];
    }
}
