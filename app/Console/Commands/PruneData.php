<?php

namespace App\Console\Commands;

use App\Domain\Privacy\Retention\RetentionPruner;
use App\Domain\Privacy\RetentionPolicy;
use App\Enums\PersonalDataExportStatus;
use App\Models\PersonalDataExport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Plazos de retención (SPEC §15, D-075), cada día a las 03:10 de Madrid (routes/console.php), antes
 * de la copia nocturna:
 * - borra por lotes lo anterior al plazo de cada tipo de RetentionPolicy con su RetentionPruner
 *   (config/privacy.php: registros de acceso, notificaciones leídas y auditoría; el chat se añade al
 *   integrar la Fase 6). Un plazo «sin límite» no borra nada,
 * - caduca las exportaciones de datos personales vencidas (borra el fichero) y da por fallidas las
 *   que llevan más de un día sin terminar,
 * - deja un resumen en el log.
 *
 * NUNCA borra horas, bolsas, tareas, proyectos ni clientes: solo toca las tablas de sus pruners y
 * personal_data_exports (tests/Feature/Privacy/PruneDataTest).
 */
#[Signature('app:prune-data')]
#[Description('Aplica los plazos de retención (registros de acceso, notificaciones leídas, auditoría y exportaciones de datos caducadas). Nunca borra horas, bolsas, tareas ni proyectos.')]
class PruneData extends Command
{
    public function handle(RetentionPolicy $policy): int
    {
        $now = CarbonImmutable::now();
        $batch = max(1, (int) config('privacy.prune_batch_size', 1000));
        /** @var array<string, class-string> $pruners */
        $pruners = (array) config('privacy.pruners', []);
        $summary = [];

        foreach (array_keys(RetentionPolicy::SETTINGS) as $type) {
            $cutoff = $policy->cutoff($type, $now);

            if ($cutoff === null) {
                $summary[$type] = 'sin límite';

                continue;
            }

            if (! isset($pruners[$type])) {
                $summary[$type] = 'sin borrado configurado';

                continue;
            }

            $summary[$type] = $this->pruner($pruners[$type])->prune($cutoff, $batch);
        }

        $summary['expired_exports'] = $this->expireExports($now);
        $summary['stuck_exports'] = $this->failStuckExports($now);

        Log::info('app:prune-data', $summary);

        $this->components->twoColumnDetail('<fg=gray>Tipo</>', '<fg=gray>Borrado</>');

        foreach ($summary as $type => $result) {
            $this->components->twoColumnDetail($type, (string) $result);
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string  $class
     */
    private function pruner(string $class): RetentionPruner
    {
        $pruner = app($class);

        if (! $pruner instanceof RetentionPruner) {
            throw new InvalidArgumentException("{$class} no implementa RetentionPruner.");
        }

        return $pruner;
    }

    /**
     * Exportaciones listas cuyo plazo de descarga ha vencido: se borra el fichero y pasan a caducadas.
     */
    private function expireExports(CarbonImmutable $now): int
    {
        $count = 0;

        PersonalDataExport::query()
            ->where('status', PersonalDataExportStatus::Ready->value)
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (PersonalDataExport $export) use (&$count): void {
                if ($export->path !== null) {
                    Storage::disk($export->disk)->delete($export->path);
                }

                $export->forceFill(['status' => PersonalDataExportStatus::Expired, 'path' => null])->save();
                $count++;
            });

        return $count;
    }

    /**
     * Exportaciones que llevan más de un día en cola o preparándose (un worker caído, por ejemplo):
     * se dan por fallidas para que la persona pueda pedir otra.
     */
    private function failStuckExports(CarbonImmutable $now): int
    {
        $count = 0;
        $limit = $now->subHours(max(1, (int) config('privacy.stuck_export_hours', 24)));

        PersonalDataExport::query()
            ->whereIn('status', [PersonalDataExportStatus::Pending->value, PersonalDataExportStatus::Processing->value])
            ->where('created_at', '<', $limit)
            ->orderBy('id')
            ->each(function (PersonalDataExport $export) use (&$count, $now): void {
                if ($export->path !== null) {
                    Storage::disk($export->disk)->delete($export->path);
                }

                $export->forceFill([
                    'status' => PersonalDataExportStatus::Failed,
                    'path' => null,
                    'error' => 'Sin terminar en el plazo: se da por fallida (app:prune-data).',
                    'finished_at' => $now,
                ])->save();
                $count++;
            });

        return $count;
    }
}
