<?php

namespace App\Console\Commands;

use App\Domain\People\ClockCorrectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Correcciones del registro sin respuesta en 7 días: quedan en discrepancia por falta de respuesta
 * (D-335), con las dos versiones y contando la original. Cada hora (routes/console.php).
 */
#[Signature('people:expire-corrections')]
#[Description('Deja en discrepancia las correcciones del registro sin respuesta en 7 días')]
class ExpireClockCorrections extends Command
{
    public function handle(ClockCorrectionService $corrections): int
    {
        $expired = $corrections->expireOverdue();
        $this->info("Correcciones en discrepancia por falta de respuesta: {$expired}.");

        return self::SUCCESS;
    }
}
