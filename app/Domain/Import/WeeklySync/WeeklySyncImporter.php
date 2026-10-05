<?php

namespace App\Domain\Import\WeeklySync;

use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\ImportRefs;
use App\Domain\Import\ClickUp\SilentOutput;
use App\Domain\Import\WeeklySync\Stages\AiUsageStage;
use App\Domain\Import\WeeklySync\Stages\ClientsStage;
use App\Domain\Import\WeeklySync\Stages\HelpStage;
use App\Domain\Import\WeeklySync\Stages\PeopleStage;
use App\Domain\Import\WeeklySync\Stages\RemindersStage;
use App\Domain\Import\WeeklySync\Stages\SuggestionsStage;
use App\Domain\Import\WeeklySync\Stages\TasksStage;
use App\Domain\Import\WeeklySync\Stages\WeeksStage;
use App\Domain\Weeklies\MyWeeklyStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importación de WeeklySync (D-149 y D-214) desde un volcado de `app:dump-weeklysync`.
 *
 * Etapas, cada una en transacciones por bloques:
 *   1. personas y clientes (PeopleStage y ClientsStage),
 *   2. semanas con su informe, audio, exenciones, envíos, apuntes, borradores y satisfacción
 *      (WeeksStage),
 *   3. tareas que casan con un proyecto (TasksStage),
 *   4. reglas, plantillas y registro de los avisos (RemindersStage),
 *   5. centro de ayuda (HelpStage) y sugerencias (SuggestionsStage),
 *   6. histórico de uso de IA (AiUsageStage).
 *
 * Idempotente con import_refs (fuente `weeklysync`): repetirla actualiza lo que cambió y añade lo
 * nuevo, sin duplicar. Lo escrito en Audax nunca se sobrescribe. Corre con los eventos de los
 * modelos apagados (sin auditoría por fila, sin avisos ni tiempo real) y deja UNA entrada de
 * auditoría con los recuentos. Con --dry-run todo va dentro de una transacción que se deshace y los
 * ficheros solo se comprueban.
 */
final class WeeklySyncImporter
{
    public const string SOURCE = 'weeklysync';

    public const string AUDIT_LOG = 'import';

    public const string AUDIT_EVENT = 'weeklysync_import';

    public function __construct(
        private readonly PeopleStage $people,
        private readonly ClientsStage $clients,
        private readonly WeeksStage $weeks,
        private readonly TasksStage $tasks,
        private readonly RemindersStage $reminders,
        private readonly HelpStage $help,
        private readonly SuggestionsStage $suggestions,
        private readonly AiUsageStage $aiUsage,
    ) {}

    /**
     * @throws RuntimeException si el volcado no es válido o no cuadra con su manifiesto
     */
    public function run(string $directory, ?WeeklySyncMappings $mappings = null, bool $dryRun = false, ?ImportOutput $output = null): WeeklySyncImportReport
    {
        $started = microtime(true);
        $dump = WeeklySyncDump::open($directory);
        $report = new WeeklySyncImportReport;
        $report->dryRun = $dryRun;
        $report->dumpedAt = $dump->dumpedAt();
        $output ??= new SilentOutput;

        $context = new WeeklySyncContext(
            dump: $dump,
            mappings: $mappings ?? new WeeklySyncMappings,
            refs: new ImportRefs(self::SOURCE),
            report: $report,
            files: new WeeklySyncFiles($dump, $report, $dryRun),
            output: $output,
            dryRun: $dryRun,
        );

        // Antes de tocar nada: todas las tablas cuadran con el manifiesto.
        foreach (WeeklySyncDump::TABLES as $table) {
            $dump->rows($table);
        }

        foreach ($dump->missingFiles() as $missing) {
            $report->warn("El volcador no encontró en el Storage: {$missing}.");
        }

        if ($dryRun) {
            DB::beginTransaction();
        }

        $activity = activity();
        $activity->disableLogging();

        try {
            Model::withoutEvents(function () use ($context, $output): void {
                $context->refs->load();

                $output->stage('Personas y clientes');
                DB::transaction(function () use ($context): void {
                    $this->people->run($context);
                    $this->clients->run($context);
                });

                $output->stage('Semanas, envíos, audios y satisfacción');
                $this->weeks->run($context);

                $output->stage('Tareas');
                $this->tasks->run($context);

                $output->stage('Avisos');
                $this->reminders->run($context);

                $output->stage('Centro de ayuda');
                $this->help->run($context);

                $output->stage('Sugerencias');
                $this->suggestions->run($context);

                $output->stage('Uso de IA');
                $this->aiUsage->run($context);
            });

            $activity->enableLogging();

            if ($dryRun) {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            if ($dryRun) {
                DB::rollBack();
            }

            throw $e;
        } finally {
            $activity->enableLogging();
            $this->forgetCaches();
        }

        $report->seconds = microtime(true) - $started;
        $report->peakMemoryBytes = memory_get_peak_usage(true);

        if (! $dryRun) {
            $this->summary($report);
        }

        return $report;
    }

    /**
     * Cachés que dependen de lo importado: los ajustes y la semana activa con sus pendientes.
     */
    private function forgetCaches(): void
    {
        Setting::flushCache();
        MyWeeklyStatus::forgetActiveSnapshot();
        MyWeeklyStatus::forgetActive(0);
        User::forgetMemberships();
    }

    /**
     * Entrada de auditoría de la ejecución: sin autor (el sistema), con los recuentos.
     */
    private function summary(WeeklySyncImportReport $report): void
    {
        activity(self::AUDIT_LOG)
            ->event(self::AUDIT_EVENT)
            ->withProperties([
                'source' => self::SOURCE,
                'dumped_at' => $report->dumpedAt,
                'counts' => $report->counts(),
                'files' => ['copied' => $report->filesCopied, 'unchanged' => $report->filesUnchanged, 'missing' => $report->filesMissing],
                'skipped' => $report->skipped(),
                'warnings' => count($report->warnings()),
                'seconds' => round($report->seconds, 1),
            ])
            ->log('Importación de WeeklySync');
    }
}
