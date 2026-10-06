<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan del día (D-256) anterior al plazo (un año por defecto): son datos de desempeño (qué pensaba
 * hacer cada persona cada día y si lo hizo). Se borra la cabecera de cada día y, con ella, sus líneas
 * y sus comentarios (borrado en cascada de la base). Las horas NO se borran nunca: las entradas que
 * estaban enlazadas a una línea se quedan sin el enlace (`day_plan_item_id` a null).
 */
final class DayPlansPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        return BatchDelete::run(
            fn () => DB::table('day_plans')->where('date', '<', $cutoff->toDateString()),
            $batchSize,
        );
    }
}
