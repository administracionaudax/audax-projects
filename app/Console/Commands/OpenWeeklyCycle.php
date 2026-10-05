<?php

namespace App\Console\Commands;

use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\WeeklyCycleOpener;
use App\Enums\AppModule;
use App\Models\WeeklyCycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Abre la semana de la weekly que toca si no hay ninguna activa (D-150, D-155, F-040 y F-070). El
 * planificador lo lanza cada día a las 00:05 de Madrid (routes/console.php): el lunes abre la semana
 * nueva y, si el servidor estuvo parado, en cuanto vuelve. Idempotente: con una semana activa no hace
 * nada. Con el módulo de la Weekly apagado (F-177), tampoco.
 */
#[Signature('weeklies:open-week')]
#[Description('Abre la semana de la weekly si no hay ninguna activa')]
class OpenWeeklyCycle extends Command
{
    public function handle(WeeklyCycleOpener $opener): int
    {
        if (! AppModules::enabled(AppModule::Weeklies)) {
            $this->info('El módulo de la Weekly está desactivado.');

            return self::SUCCESS;
        }

        $before = WeeklyCycle::query()->active()->value('id');
        $cycle = $opener->ensureOpen();

        $this->info($before === null
            ? "Semana abierta: {$cycle->number} ({$cycle->label})."
            : "Ya había una semana activa: {$cycle->number}.");

        return self::SUCCESS;
    }
}
