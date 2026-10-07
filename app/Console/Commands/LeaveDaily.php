<?php

namespace App\Console\Commands;

use App\Domain\Absences\LeaveReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Vacaciones y permisos (Fase 11, R3; D-362 y D-369): cada día a las 07:30 de Madrid
 * (routes/console.php) asigna el año en curso y el siguiente, avisa de los saldos a punto de
 * caducar y de los justificantes pendientes. Nada con el módulo `people` apagado.
 */
#[Signature('people:leave-daily')]
#[Description('Asigna los saldos de vacaciones y permisos y avisa de lo que caduca y de los justificantes pendientes')]
class LeaveDaily extends Command
{
    public function handle(LeaveReminders $reminders): int
    {
        $result = $reminders->run();
        $this->info("Asignaciones: {$result['accrued']}. Avisos de caducidad: {$result['expiring']}. Justificantes pendientes: {$result['documents']}.");

        return self::SUCCESS;
    }
}
