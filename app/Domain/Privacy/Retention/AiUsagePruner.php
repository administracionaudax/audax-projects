<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Uso de la IA (D-225) anterior al plazo (un año por defecto): no se borra, porque la página «Uso de
 * IA» suma el coste por fechas, pero deja de decir quién lo pidió y sobre qué (persona, objeto y
 * metadatos, que llevan `target_user_id`). Por lotes, como BatchDelete.
 */
final class AiUsagePruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        $batchSize = max(1, $batchSize);
        $query = fn () => DB::table('ai_usage')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($personal) => $personal->whereNotNull('user_id')->orWhereNotNull('subject_type')->orWhereNotNull('metadata'));
        $updated = 0;

        do {
            $ids = $query()->orderBy('id')->limit($batchSize)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $updated += DB::table('ai_usage')->whereIn('id', $ids)->update([
                'user_id' => null,
                'subject_type' => null,
                'subject_id' => null,
                'metadata' => null,
            ]);
        } while (count($ids) === $batchSize);

        return $updated;
    }
}
