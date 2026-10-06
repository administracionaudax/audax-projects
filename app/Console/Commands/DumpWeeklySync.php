<?php

namespace App\Console\Commands;

use App\Console\Support\CommandImportOutput;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncConnector;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncCredentials;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncDumper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Volcado de WeeklySync (D-213), en el Mac del propietario: lee la base de Supabase en SOLO
 * LECTURA y descarga del Storage los ficheros que usan sus filas, en una carpeta nueva con su
 * manifest.json. Las credenciales salen de un fichero local fuera de Git (600) y nunca se imprimen.
 * El volcado lo lee después `app:import-weeklysync` en el servidor.
 */
#[Signature('app:dump-weeklysync
    {directorio : Carpeta NUEVA donde escribir el volcado (se crea con permisos 700)}
    {--credenciales='.WeeklySyncCredentials::DEFAULT_PATH.' : Fichero con WEEKLYSYNC_DB_URL, WEEKLYSYNC_DB_PASSWORD, WEEKLYSYNC_URL y WEEKLYSYNC_SERVICE_KEY}
    {--sin-ficheros : Solo las tablas, sin descargar audios, vídeos ni adjuntos}
    {--api : Lee las tablas por la API REST con la clave secreta, sin la contraseña de la base (D-237)}')]
#[Description('Vuelca la base y los ficheros de WeeklySync (solo lectura) para importarlos después')]
class DumpWeeklySync extends Command
{
    public function handle(WeeklySyncDumper $dumper, WeeklySyncConnector $connector): int
    {
        $directory = (string) $this->argument('directorio');
        $withFiles = ! $this->option('sin-ficheros');

        try {
            $credentials = WeeklySyncCredentials::load((string) $this->option('credenciales'), $withFiles || $this->option('api'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Volcado de WeeklySync (solo lectura)');

        try {
            $source = $this->option('api') ? $connector->apiSource($credentials) : $connector->source($credentials);
            $storage = $withFiles ? $connector->storage($credentials) : null;
            $result = $dumper->dump($directory, $source, $storage, new CommandImportOutput($this->output));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        foreach ($result->tables as $table => $count) {
            $rows[] = [$table, $count === null ? 'no existe' : $count];
        }
        $this->table(['Tabla', 'Filas'], $rows);

        $this->line(sprintf('  Ficheros: %d (%.1f MB)', $result->files, $result->bytes / 1048576));

        if ($result->missing !== []) {
            $this->components->warn('Ficheros a los que apunta alguna fila pero que no están en el Storage ('.count($result->missing).'):');
            foreach ($result->missing as $missing) {
                $this->line("    - {$missing}");
            }
        }

        $this->line(sprintf('  Tiempo: %.1f s · Memoria máxima: %.0f MB', $result->seconds, memory_get_peak_usage(true) / 1048576));
        $this->components->info("Volcado listo en {$directory}. Súbelo al servidor como se explica en docs/PLAN-FASE-10.md (10.8) y bórralo del Mac al terminar.");

        return self::SUCCESS;
    }
}
