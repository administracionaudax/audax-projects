<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registro de avisos de la Weekly (F-108, D-202) anterior al plazo (un año por defecto): lleva el
 * nombre y el email de quien lo recibió. Las filas de un disparo que aún podría repetirse (las de
 * la semana activa) son recientes y quedan dentro de cualquier plazo, así que la deduplicación no
 * se pierde.
 */
final class WeeklyReminderLogsPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        return BatchDelete::run(
            fn () => DB::table('weekly_reminder_logs')->where('created_at', '<', $cutoff),
            $batchSize,
        );
    }
}
