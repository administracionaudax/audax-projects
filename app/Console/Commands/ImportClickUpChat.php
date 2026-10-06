<?php

namespace App\Console\Commands;

use App\Domain\Import\ClickUp\Chat\ChatImporter;
use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Importa el chat de ClickUp (D-274 a D-279) desde el volcado de chat.py en {ruta}: canales,
 * mensajes, hilos, reacciones, menciones y adjuntos, con sus fechas y autores. Las personas se
 * casan con el fichero de personas de la importación de ClickUp (por defecto {ruta}/personas.json,
 * fuera de Git); por eso va DESPUÉS de app:import-clickup (clientes, proyectos y cuentas).
 *
 * Idempotente: se puede repetir para traer lo último. --dry-run lo hace todo dentro de una
 * transacción que se deshace (sin copiar adjuntos) y muestra el informe. --solo-directos importa
 * solo los mensajes directos y los grupos: la opción para que cualquier persona traiga los suyos
 * con su propio volcado (chat.py --solo-directos con su token). No avisa a nadie.
 */
#[Signature('app:import-clickup-chat
    {ruta : Carpeta del volcado del chat de ClickUp (channels.json, messages/, members/…)}
    {--personas= : Fichero de personas de la importación de ClickUp (por defecto, {ruta}/personas.json)}
    {--dry-run : Simula la importación y deshace todo al terminar}
    {--solo-directos : Solo los mensajes directos y los grupos del dueño del volcado}')]
#[Description('Importa el chat de ClickUp (canales, mensajes, hilos, reacciones y adjuntos)')]
class ImportClickUpChat extends Command
{
    public function handle(ChatImporter $importer): int
    {
        $directory = rtrim((string) $this->argument('ruta'), '/');
        $peoplePath = (string) ($this->option('personas') ?: $directory.'/personas.json');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $people = PeopleFile::load($peoplePath);
        } catch (ValidationException $e) {
            $this->components->error('El fichero de personas no es válido:');
            foreach ($e->errors() as $field => $messages) {
                $this->line("  {$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Simulación de la importación del chat de ClickUp (no se guarda nada)' : 'Importación del chat de ClickUp');

        try {
            $report = $importer->run($directory, $people, $dryRun, (bool) $this->option('solo-directos'), $this->progress());
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        return self::SUCCESS;
    }

    private function progress(): ImportOutput
    {
        $command = $this;

        return new class($command) implements ImportOutput
        {
            private ?ProgressBar $bar = null;

            public function __construct(private readonly ImportClickUpChat $command) {}

            public function stage(string $label): void
            {
                $this->command->getOutput()->writeln("  <fg=gray>›</> {$label}");
            }

            public function progressStart(int $max): void
            {
                $this->bar = $this->command->getOutput()->createProgressBar($max);
                $this->bar->start();
            }

            public function progressAdvance(int $steps = 1): void
            {
                $this->bar?->advance($steps);
            }

            public function progressFinish(): void
            {
                $this->bar?->finish();
                $this->bar = null;
                $this->command->getOutput()->newLine();
            }
        };
    }

    private function printReport(ImportReport $report): void
    {
        $this->newLine();
        $this->components->info($report->dryRun ? 'Informe (simulación: nada se ha guardado)' : 'Informe');

        $this->table(['Tipo', 'Creados', 'Actualizados', 'Sin cambios', 'Omitidos'], $report->rows());

        if ($report->warnings() !== []) {
            $this->line('  Avisos:');
            foreach ($report->warnings() as $warning => $count) {
                $this->line('    - '.$warning.($count > 1 ? " ({$count})" : ''));
            }
        }

        $this->newLine();
        $this->line(sprintf('  Tiempo: %.1f s · Memoria máxima: %.0f MB', $report->seconds, $report->peakMemoryBytes / 1048576));
    }
}
