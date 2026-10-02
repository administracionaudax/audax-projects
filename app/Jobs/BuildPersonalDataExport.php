<?php

namespace App\Jobs;

use App\Domain\Privacy\Export\PersonalDataArchive;
use App\Domain\Privacy\RetentionPolicy;
use App\Enums\PersonalDataExportStatus;
use App\Models\PersonalDataExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera el ZIP de una exportación de datos personales (D-075) en la cola default: pendiente →
 * preparándose → lista (o fallida). Solo procesa las pendientes: si la cola la repite, no hace nada.
 * El ZIP va al disco privado (exports/personal-data/{uuid}.zip) y se puede descargar durante
 * RetentionPolicy::exportDays() días; después lo borra app:prune-data.
 *
 * Si falla, la persona solo ve «Ha fallado» (sin traza técnica); el detalle queda en el log y en
 * la columna error para el admin.
 */
class BuildPersonalDataExport implements ShouldQueue
{
    use Queueable;

    /** Segundos como máximo. */
    public int $timeout = 600;

    /** Un solo intento: si falla, la persona puede pedir otra. */
    public int $tries = 1;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('default');
    }

    public function handle(PersonalDataArchive $archive, RetentionPolicy $policy): void
    {
        /** @var PersonalDataExport|null $export */
        $export = PersonalDataExport::query()->with('subject')->find($this->exportId);

        if ($export === null || $export->status !== PersonalDataExportStatus::Pending) {
            return;
        }

        $export->forceFill(['status' => PersonalDataExportStatus::Processing, 'started_at' => now()])->save();
        $path = 'exports/personal-data/'.Str::uuid().'.zip';

        try {
            $size = $archive->build($export->subject, $export->disk, $path);
        } catch (Throwable $exception) {
            rescue(fn () => Storage::disk($export->disk)->delete($path), report: false);
            $this->markFailed($export, $exception);
            report($exception);

            return;
        }

        $now = now();
        $updated = PersonalDataExport::query()
            ->whereKey($export->id)
            ->where('status', PersonalDataExportStatus::Processing->value)
            ->update([
                'status' => PersonalDataExportStatus::Ready->value,
                'path' => $path,
                'size_bytes' => $size,
                'error' => null,
                'finished_at' => $now,
                'expires_at' => $now->addDays($policy->exportDays()),
                'updated_at' => $now,
            ]);

        // Si mientras tanto se dio por fallida (app:prune-data), el fichero no se queda huérfano.
        if ($updated === 0) {
            Storage::disk($export->disk)->delete($path);
        }
    }

    /**
     * La cola la da por fallida (p. ej., al superar el tiempo máximo).
     */
    public function failed(?Throwable $exception): void
    {
        $export = PersonalDataExport::query()->find($this->exportId);

        if ($export !== null && $export->status->inProgress()) {
            $this->markFailed($export, $exception);
        }
    }

    private function markFailed(PersonalDataExport $export, ?Throwable $exception): void
    {
        $error = $exception === null ? 'Sin detalle' : Str::limit($exception::class.': '.$exception->getMessage(), 1000);

        $export->forceFill([
            'status' => PersonalDataExportStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        Log::warning('Exportación de datos personales fallida', ['export_id' => $export->id, 'error' => $error]);
    }
}
