<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Entradas de la auditoría anteriores al plazo (como mínimo 12 meses, RetentionPolicy). Solo el
 * registro de cambios: los datos (horas, bolsas, tareas, proyectos…) nunca se tocan.
 */
final class ActivityLogPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        return BatchDelete::run(
            fn () => DB::table('activity_log')->where('created_at', '<', $cutoff),
            $batchSize,
        );
    }
}
