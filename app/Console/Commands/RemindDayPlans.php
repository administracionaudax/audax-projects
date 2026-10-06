<?php

namespace App\Console\Commands;

use App\Domain\DayPlan\DayPlanReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recordatorio del plan del día (D-252): cada 5 minutos (routes/console.php); solo envía a la hora
 * límite de Madrid (08:30) y en las tres horas siguientes, una vez por persona y día
 * (App\Domain\DayPlan\DayPlanReminders).
 */
#[Signature('day-plan:remind')]
#[Description('Recordatorio del plan del día a quien aún no lo ha escrito en un día con jornada')]
class RemindDayPlans extends Command
{
    public function handle(DayPlanReminders $reminders): int
    {
        $sent = $reminders->sendDue();
        $this->info("Recordatorios del plan del día enviados: {$sent}.");

        return self::SUCCESS;
    }
}
