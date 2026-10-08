<?php

namespace App\Jobs;

use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\Holded\HoldedSync;
use App\Domain\Billing\Holded\HoldedSyncBusy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * «Sincronizar ahora» (Fase 12, D-387): la sincronización con Holded en la cola, lanzada por un
 * admin. El resultado queda en holded_sync_runs (lo enseña /facturacion/ajustes). Un solo intento:
 * si falla, el error queda en la ejecución y la siguiente es la de la noche.
 */
class SyncHolded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly ?User $user = null) {}

    public function handle(HoldedSync $sync): void
    {
        try {
            $sync->run(app(HoldedApi::class), 'manual', $this->user);
        } catch (HoldedSyncBusy|HoldedRequestFailed) {
            // Ya queda en holded_sync_runs (o hay otra en marcha): nada más que hacer.
        }
    }
}
