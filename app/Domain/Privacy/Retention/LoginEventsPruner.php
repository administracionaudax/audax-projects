<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Registros de acceso (correctos y fallidos) anteriores al plazo. */
final class LoginEventsPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        return BatchDelete::run(
            fn () => DB::table('login_events')->where('created_at', '<', $cutoff),
            $batchSize,
        );
    }
}
