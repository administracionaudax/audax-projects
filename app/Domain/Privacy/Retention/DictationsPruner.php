<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Dictados de la Weekly (D-152 y D-202) anteriores al plazo (tres meses por defecto). Su texto ya
 * está en el apunte o la tarea donde se pegó, así que solo se borra el borrador del dictado. Si
 * algún audio se quedó en el disco (un fallo antes de transcribirlo), se borra también, después de
 * la fila.
 */
final class DictationsPruner implements RetentionPruner
{
    public function prune(CarbonImmutable $cutoff, int $batchSize): int
    {
        $batchSize = max(1, $batchSize);
        $deleted = 0;

        do {
            $rows = DB::table('dictations')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'disk', 'path']);

            if ($rows->isEmpty()) {
                break;
            }

            $deleted += DB::table('dictations')->whereIn('id', $rows->pluck('id')->all())->delete();

            foreach ($rows as $row) {
                if (is_string($row->disk) && is_string($row->path) && $row->path !== '') {
                    Storage::disk($row->disk)->delete($row->path);
                }
            }
        } while ($rows->count() === $batchSize);

        return $deleted;
    }
}
