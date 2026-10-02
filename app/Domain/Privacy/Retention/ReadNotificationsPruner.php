<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones LEÍDAS hace más del plazo (por su read_at). Las que siguen sin leer no se borran
 * nunca: la persona aún no las ha visto.
 */
final class ReadNotificationsPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        return BatchDelete::run(
            fn () => DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', $cutoff),
            $batchSize,
        );
    }
}
