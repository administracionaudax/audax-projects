<?php

namespace App\Events\Weeklies;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Progreso del informe o del audio de una semana (F-072 y F-084, D-190): lo emiten los Jobs de la
 * cola `ai` al empezar, en cada cliente y al terminar, por el canal privado `weeklies.{id}` (quien
 * puede ver la semana). Sale al momento (ShouldBroadcastNow) y, si Reverb no está, no rompe el Job
 * (ShouldRescue): la página sondea entonces `weeklies.report.status`.
 */
final class WeeklyGenerationUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    /**
     * @param  'report'|'audio'  $kind
     */
    public function __construct(
        public readonly int $cycleId,
        public readonly string $kind,
        public readonly string $state,
        public readonly ?string $step = null,
        public readonly int $done = 0,
        public readonly int $total = 0,
        public readonly ?string $error = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('weeklies.'.$this->cycleId)];
    }

    public function broadcastAs(): string
    {
        return 'weekly.progress';
    }

    /**
     * @return array{cycle_id: int, kind: string, state: string, step: string|null, done: int, total: int, error: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'cycle_id' => $this->cycleId,
            'kind' => $this->kind,
            'state' => $this->state,
            'step' => $this->step,
            'done' => $this->done,
            'total' => $this->total,
            'error' => $this->error,
        ];
    }
}
