<?php

namespace App\Console\Commands;

use App\Domain\People\MonthCloser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cierre mensual del registro (PLAN-FASE-11 §7.5; D-347): cada día a las 06:00 de Madrid
 * (routes/console.php). El día 1 genera el resumen del mes anterior de cada persona (si un día
 * falla, lo hace el siguiente: solo genera los que faltan) y avisa para confirmarlo; recuerda a
 * los 3 y a los 7 días los que siguen pendientes. Nada con el módulo `people` apagado.
 */
#[Signature('people:close-months')]
#[Description('Genera los cierres mensuales del registro de jornada y recuerda confirmarlos')]
class CloseMonths extends Command
{
    public function handle(MonthCloser $closer): int
    {
        $result = $closer->runDue();
        $this->info("Cierres generados: {$result['generated']}. Recordatorios: {$result['reminded']}.");

        return self::SUCCESS;
    }
}
