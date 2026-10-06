<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Satisfaction\SatisfactionUpdater;
use App\Events\Weeklies\WeeklyCycleClosed;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Lo que pasa en segundo plano al cerrar una semana (F-093, F-094 y F-095), como el
 * runPostCloseProcessing de WeeklySync: la satisfacción de los clientes (SatisfactionUpdater) y,
 * después, el evento WeeklyCycleClosed para el aviso «weekly cerrada». Si la IA falla, el evento
 * sale igualmente: el cierre ya está hecho.
 */
final class UpdateWeeklySatisfaction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    /** Único (D-222): no se encola otro igual mientras este espera o se ejecuta. */
    public int $uniqueFor = AiQueue::UNIQUE_FOR;

    public function __construct(
        public readonly int $cycleId,
        public readonly ?int $userId = null,
        public readonly ?int $nextCycleId = null,
    ) {
        $this->onQueue(AiQueue::HIGH);
    }

    public function uniqueId(): string
    {
        return (string) $this->cycleId;
    }

    public function handle(SatisfactionUpdater $updater): void
    {
        $cycle = WeeklyCycle::query()->find($this->cycleId);

        if ($cycle === null || ! $cycle->isClosed()) {
            return;
        }

        try {
            $updater->update($cycle, $this->userId === null ? null : User::query()->find($this->userId));
        } catch (Throwable $e) {
            report($e);
        }

        event(new WeeklyCycleClosed($cycle->id, $this->userId, $this->nextCycleId));
    }
}
