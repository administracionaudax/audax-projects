<?php

namespace App\Console\Commands;

use App\Domain\Import\ClickUp\Comments\TaskCommentImporter;
use Illuminate\Console\Command;
use JsonException;

/**
 * Importa los comentarios de las tareas de ClickUp (TaskCommentImporter). Repetible sin duplicar y
 * sin avisar a nadie; con --dry-run solo cuenta.
 */
class ImportClickUpComments extends Command
{
    protected $signature = 'app:import-clickup-comments
        {fichero : JSON de comentarios descargado de ClickUp ({id de tarea: [comentarios]})}
        {--dry-run : Simula: no guarda nada}';

    protected $description = 'Importa los comentarios de las tareas de ClickUp (después de app:import-clickup)';

    public function handle(TaskCommentImporter $importer): int
    {
        $path = (string) $this->argument('fichero');

        try {
            $dump = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->components->error("No se puede leer el JSON de comentarios: {$path}");

            return self::FAILURE;
        }

        if (! is_array($dump)) {
            $this->components->error('El fichero no tiene el formato esperado.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $importer->import($dump, $dryRun);

        $this->components->info($dryRun ? 'Simulación: nada se ha guardado.' : 'Comentarios importados.');
        $this->table(['Resultado', 'Comentarios'], [
            ['Creados', $counts['created']],
            ['Ya importados', $counts['unchanged']],
            ['Avisos automáticos de ClickBot (no se importan)', $counts['bot']],
            ['Sin tarea en la app', $counts['no_task']],
            ['Sin autor en la app', $counts['no_author']],
            ['Vacíos', $counts['empty']],
        ]);

        return self::SUCCESS;
    }
}
