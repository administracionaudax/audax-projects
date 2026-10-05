<?php

namespace App\Domain\Import\WeeklySync\Dump;

use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\SilentOutput;
use App\Domain\Import\WeeklySync\WeeklySyncDump;
use RuntimeException;

/**
 * Volcador de WeeklySync (D-213): escribe en una carpeta NUEVA (700, ficheros 600) las tablas de
 * WeeklySyncDump::TABLES y los ficheros del Storage que usan sus filas (audios del informe, vídeos y
 * manual de la ayuda, adjuntos de las sugerencias), y al final el manifest.json con los recuentos y
 * el sha256 de todo. Solo se descargan los ficheros a los que apunta alguna fila: los MP3 de
 * regeneraciones anteriores que quedaron huérfanos en el bucket no se copian.
 *
 * No escribe nada en el origen: la base se lee en una transacción de solo lectura
 * (PostgresWeeklySyncSource) y el Storage solo con GET (SupabaseStorage).
 */
final class WeeklySyncDumper
{
    /**
     * @throws RuntimeException si la carpeta ya existe con contenido o algo no se puede escribir
     */
    public function dump(string $directory, WeeklySyncSource $source, ?WeeklySyncStorage $storage, ?ImportOutput $output = null): DumpResult
    {
        $started = microtime(true);
        $output ??= new SilentOutput;
        $directory = rtrim($directory, '/');
        $this->prepare($directory);

        $tables = [];
        $references = [];

        try {
            $output->stage('Tablas');
            $output->progressStart(count(WeeklySyncDump::TABLES));

            foreach (WeeklySyncDump::TABLES as $table) {
                if (! $source->has($table)) {
                    $tables[$table] = ['rows' => 0, 'missing' => true];
                    $output->progressAdvance();

                    continue;
                }

                $rows = [];
                foreach ($source->rows($table) as $row) {
                    $rows[] = $row;
                }

                $path = $directory.'/tables/'.$table.'.json';
                $this->write($path, self::encodeRows($rows));
                $tables[$table] = ['rows' => count($rows), 'sha256' => (string) hash_file('sha256', $path)];

                foreach (WeeklySyncDump::references($table, $rows) as $reference) {
                    $references[$reference[0].'/'.$reference[1]] = $reference;
                }

                $output->progressAdvance();
            }

            $output->progressFinish();
        } finally {
            $source->close();
        }

        $files = [];
        $missing = [];

        if ($storage !== null && $references !== []) {
            $output->stage('Ficheros del Storage');
            $output->progressStart(count($references));

            foreach ($references as $key => [$bucket, $path]) {
                $target = $directory.'/storage/'.$bucket.'/'.$path;
                $this->ensureDirectory(dirname($target));

                if ($storage->download($bucket, $path, $target)) {
                    @chmod($target, 0o600);
                    $files[$key] = [
                        'bucket' => $bucket,
                        'path' => $path,
                        'size' => (int) filesize($target),
                        'sha256' => (string) hash_file('sha256', $target),
                    ];
                } else {
                    $missing[] = $key;
                }

                $output->progressAdvance();
            }

            $output->progressFinish();
        } elseif ($references !== []) {
            $missing = array_keys($references);
        }

        ksort($files);
        sort($missing);

        $manifest = [
            'format' => WeeklySyncDump::FORMAT,
            'source' => WeeklySyncDump::SOURCE,
            'dumped_at' => now()->toIso8601String(),
            'tables' => $tables,
            'files' => $files,
            'missing_files' => $missing,
        ];

        $this->write($directory.'/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        return new DumpResult(
            tables: array_map(fn (array $table): ?int => ($table['missing'] ?? false) === true ? null : (int) $table['rows'], $tables),
            files: count($files),
            bytes: array_sum(array_column($files, 'size')),
            missing: $missing,
            seconds: microtime(true) - $started,
        );
    }

    /**
     * Una fila por línea: el volcado se puede revisar y comparar con diff.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function encodeRows(array $rows): string
    {
        if ($rows === []) {
            return "[]\n";
        }

        $lines = array_map(
            fn (array $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $rows,
        );

        return "[\n".implode(",\n", $lines)."\n]\n";
    }

    private function prepare(string $directory): void
    {
        if (file_exists($directory)) {
            if (! is_dir($directory)) {
                throw new RuntimeException("{$directory} existe y no es una carpeta.");
            }

            if ((scandir($directory) ?: []) !== ['.', '..']) {
                throw new RuntimeException("La carpeta {$directory} no está vacía: usa una nueva para cada volcado.");
            }

            @chmod($directory, 0o700);
        }

        $this->ensureDirectory($directory);
        $this->ensureDirectory($directory.'/tables');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se puede crear la carpeta {$directory}.");
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("No se puede escribir {$path}.");
        }

        @chmod($path, 0o600);
    }
}
