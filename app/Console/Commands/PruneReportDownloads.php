<?php

namespace App\Console\Commands;

use App\Models\ReportDownload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Borra los informes que salieron como enlace de descarga (D-141, más de 10 MB) una vez caducado
 * su enlace (7 días): el fichero del disco privado y su fila. Cada día en routes/console.php.
 */
#[Signature('reports:prune-downloads')]
#[Description('Borra los informes enviados como enlace cuyo enlace ya ha caducado')]
class PruneReportDownloads extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        ReportDownload::query()
            ->where('expires_at', '<=', now())
            ->chunkById(100, function (Collection $downloads) use (&$deleted): void {
                /** @var ReportDownload $download */
                foreach ($downloads as $download) {
                    Storage::disk($download->disk)->delete($download->path);
                    $download->delete();
                    $deleted++;
                }
            }, 'id');

        $this->info("Informes caducados borrados: {$deleted}.");

        return self::SUCCESS;
    }
}
