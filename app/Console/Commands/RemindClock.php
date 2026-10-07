<?php

namespace App\Console\Commands;

use App\Domain\People\ClockReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Avisos del registro de jornada (D-339): cada 5 minutos (routes/console.php). Entrada sin fichar,
 * salida sin fichar y jornada de ayer sin cerrar, una vez por persona, día y tipo
 * (App\Domain\People\ClockReminders). Nada con el módulo `people` apagado.
 */
#[Signature('people:remind')]
#[Description('Avisos del registro de jornada: entrada, salida y jornada sin cerrar')]
class RemindClock extends Command
{
    public function handle(ClockReminders $reminders): int
    {
        $sent = $reminders->sendDue();
        $this->info("Avisos del registro de jornada enviados: {$sent}.");

        return self::SUCCESS;
    }
}
