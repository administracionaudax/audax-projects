<?php

namespace App\Console\Commands;

use App\Domain\Reports\Delivery\ScheduleRunner;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Encola los envíos programados que ya tocan (D-141): activos con next_run_at pasado. Cada uno lo
 * reclama ScheduleRunner de forma atómica, comprueba los permisos actuales del propietario y
 * resuelve el periodo relativo del día. Programado cada 5 minutos en routes/console.php.
 */
#[Signature('reports:send-scheduled')]
#[Description('Encola los envíos programados de informes que ya tocan')]
class SendScheduledReports extends Command
{
    public function handle(ScheduleRunner $runner): int
    {
        $now = CarbonImmutable::now();
        $queued = 0;

        ReportSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->with('owner')
            ->chunkById(100, function (Collection $schedules) use ($runner, $now, &$queued): void {
                /** @var ReportSchedule $schedule */
                foreach ($schedules as $schedule) {
                    if ($runner->run($schedule, $now) !== null) {
                        $queued++;
                    }
                }
            });

        $this->info("Envíos encolados: {$queued}.");

        return self::SUCCESS;
    }
}
