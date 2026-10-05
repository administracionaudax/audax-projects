<?php

namespace App\Console\Commands;

use App\Console\Support\CommandImportOutput;
use App\Domain\Import\WeeklySync\WeeklySyncImporter;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport;
use App\Domain\Import\WeeklySync\WeeklySyncMappings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Importa WeeklySync (D-149 y D-214) desde un volcado de `app:dump-weeklysync`: semanas, envíos,
 * borradores, exenciones, satisfacción, audios, avisos, ayuda, sugerencias y uso de IA.
 *
 * Los ficheros de correspondencias son opcionales (por defecto {volcado}/personas.json y
 * {volcado}/clientes.json si existen); su formato está en WeeklySyncMappings. Idempotente: se
 * repite el día del cambio con WeeklySync congelado. --dry-run lo hace todo dentro de una
 * transacción que se deshace, sin copiar ficheros, y muestra el informe. No envía nada.
 */
#[Signature('app:import-weeklysync
    {volcado : Carpeta del volcado (manifest.json, tables/ y storage/)}
    {--personas= : Fichero de correspondencias de personas (por defecto, {volcado}/personas.json si existe)}
    {--clientes= : Fichero de correspondencias de clientes (por defecto, {volcado}/clientes.json si existe)}
    {--dry-run : Simula la importación y deshace todo al terminar}')]
#[Description('Importa las weeklies, la ayuda y las sugerencias de un volcado de WeeklySync')]
class ImportWeeklySync extends Command
{
    public function handle(WeeklySyncImporter $importer): int
    {
        $directory = rtrim((string) $this->argument('volcado'), '/');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $mappings = WeeklySyncMappings::load(
                $this->mappingPath('personas', $directory.'/personas.json'),
                $this->mappingPath('clientes', $directory.'/clientes.json'),
            );
        } catch (ValidationException $e) {
            $this->components->error('El fichero de correspondencias no es válido:');
            foreach ($e->errors() as $field => $messages) {
                $this->line("  {$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Simulación de la importación de WeeklySync (no se guarda nada)' : 'Importación de WeeklySync');

        try {
            $report = $importer->run($directory, $mappings, $dryRun, new CommandImportOutput($this->output));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        return self::SUCCESS;
    }

    private function mappingPath(string $option, string $default): ?string
    {
        $value = $this->option($option);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_file($default) ? $default : null;
    }

    private function printReport(WeeklySyncImportReport $report): void
    {
        $this->newLine();
        $this->components->info($report->dryRun ? 'Informe (simulación: nada se ha guardado)' : 'Informe');

        if ($report->dumpedAt !== null) {
            $this->line("  Volcado del {$report->dumpedAt}");
        }

        $this->table(['Tipo', 'Creados', 'Actualizados', 'Sin cambios', 'Omitidos'], $report->rows());

        $rows = [];
        foreach ($report->differences() as $row) {
            $rows[] = [
                $row['table'],
                $row['manifest'] ?? 'no existe',
                $row['read'],
                $row['imported'],
                $row['skipped'],
                $row['ok'] ? 'sí' : 'NO',
            ];
        }
        $this->table(['Tabla', 'Volcado', 'Leídas', 'Importadas', 'Omitidas', 'Cuadra'], $rows);

        $this->line(sprintf(
            '  Ficheros: %d %s (%.1f MB), %d ya estaban y %d no están en el volcado.',
            $report->filesCopied,
            $report->dryRun ? 'por copiar' : 'copiados',
            $report->bytesCopied / 1048576,
            $report->filesUnchanged,
            $report->filesMissing,
        ));

        if ($report->skipped() !== []) {
            $this->line('  Omitidos:');
            foreach ($report->skipped() as $reason => $count) {
                $this->line("    - {$reason}: {$count}");
            }
        }

        if ($report->warnings() !== []) {
            $this->line('  Avisos:');
            foreach ($report->warnings() as $warning => $count) {
                $this->line('    - '.$warning.($count > 1 ? " ({$count})" : ''));
            }
        }

        if ($report->hasDifferences()) {
            $this->components->warn('Alguna tabla no cuadra con el volcado: revisa la columna «Cuadra».');
        }

        $this->newLine();
        $this->line(sprintf('  Tiempo: %.1f s · Memoria máxima: %.0f MB', $report->seconds, $report->peakMemoryBytes / 1048576));
    }
}
