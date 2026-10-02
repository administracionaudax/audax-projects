<?php

namespace App\Domain\Privacy\Export;

use App\Domain\Privacy\RetentionPolicy;
use App\Domain\Reports\Export\TableExporter;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * ZIP con los datos personales de una persona (D-075): un JSON y un CSV por sección
 * (config/privacy.php, export_sections) y un LEEME.txt que explica el contenido.
 *
 * - Se escribe en ficheros temporales, sección a sección y fila a fila (sin cargarlo todo en
 *   memoria), y el ZIP terminado se copia al disco privado.
 * - JSON en UTF-8 con los valores tal cual (números, verdadero/falso, null); CSV para Excel en
 *   español (separador «;», BOM y textos nunca interpretados como fórmulas, como las
 *   exportaciones de los informes).
 */
final class PersonalDataArchive
{
    public function __construct(
        private readonly TableExporter $exporter,
        private readonly RetentionPolicy $policy,
    ) {}

    /**
     * Secciones registradas, en orden.
     *
     * @return list<PersonalDataSection>
     */
    public function sections(): array
    {
        $classes = config('privacy.export_sections', []);
        $sections = [];

        foreach (is_array($classes) ? $classes : [] as $class) {
            $section = is_string($class) ? app($class) : null;

            if (! $section instanceof PersonalDataSection) {
                throw new InvalidArgumentException('Sección de la exportación de datos personales no válida: '.(is_string($class) ? $class : gettype($class)).'.');
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Genera el ZIP de $user y lo guarda en $disk:$path. Devuelve su tamaño en bytes.
     */
    public function build(User $user, string $disk, string $path): int
    {
        $workDir = $this->temporaryDirectory();
        $zipPath = $workDir.'/datos.zip';

        $zip = new ZipArchive;
        $open = false;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se ha podido crear el ZIP de la exportación.');
            }

            $open = true;
            $files = [];

            foreach ($this->sections() as $section) {
                $json = "{$workDir}/{$section->key()}.json";
                $csv = "{$workDir}/{$section->key()}.csv";
                $rows = $this->writeSection($section, $user, $json, $csv);

                $zip->addFile($json, "{$section->key()}.json");
                $zip->addFile($csv, "{$section->key()}.csv");
                $files[] = ['section' => $section, 'rows' => $rows];
            }

            $zip->addFromString('LEEME.txt', $this->readme($user, $files));
            $open = false;

            if (! $zip->close()) {
                throw new RuntimeException('No se ha podido cerrar el ZIP de la exportación.');
            }

            $size = (int) filesize($zipPath);
            $stream = fopen($zipPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('No se ha podido leer el ZIP de la exportación.');
            }

            try {
                $stored = Storage::disk($disk)->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $stored) {
                throw new RuntimeException("No se ha podido guardar la exportación en {$disk}:{$path}.");
            }

            return $size;
        } finally {
            // Si algo ha fallado a medias, el ZIP se descarta sin escribirse.
            if ($open) {
                $zip->unchangeAll();
                $zip->close();
            }

            $this->removeDirectory($workDir);
        }
    }

    /**
     * Escribe el JSON (una lista de objetos) y el CSV de una sección. Devuelve las filas.
     */
    private function writeSection(PersonalDataSection $section, User $user, string $jsonPath, string $csvPath): int
    {
        $columns = $section->columns();
        $keys = array_keys($columns);
        $json = fopen($jsonPath, 'wb');

        if ($json === false) {
            throw new RuntimeException("No se ha podido escribir {$jsonPath}.");
        }

        $count = 0;
        $rows = (function () use ($section, $user, $keys, $json, &$count) {
            fwrite($json, '[');

            foreach ($section->rows($user) as $row) {
                $ordered = [];

                foreach ($keys as $key) {
                    $ordered[$key] = $row[$key] ?? null;
                }

                $encoded = json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                fwrite($json, ($count === 0 ? "\n" : ",\n").'    '.str_replace("\n", "\n    ", $encoded));
                $count++;

                yield array_values($ordered);
            }

            fwrite($json, $count === 0 ? "]\n" : "\n]\n");
        })();

        try {
            $this->exporter->withMaxRows(PHP_INT_MAX)->write($csvPath, array_values($columns), $rows, 'csv');
        } finally {
            fclose($json);
        }

        return $count;
    }

    /**
     * @param  list<array{section: PersonalDataSection, rows: int}>  $files
     */
    private function readme(User $user, array $files): string
    {
        $lines = [
            self::text('privacy.export.readme.title'),
            str_repeat('=', mb_strlen(self::text('privacy.export.readme.title'))),
            '',
            self::text('privacy.export.readme.person', ['name' => $user->name, 'email' => $user->email]),
            self::text('privacy.export.readme.generated', ['date' => LocalTime::now()->format('d/m/Y H:i')]),
            '',
            self::text('privacy.export.readme.intro'),
            '',
            self::text('privacy.export.readme.files'),
        ];

        foreach ($files as $file) {
            $lines[] = self::text('privacy.export.readme.file', [
                'name' => $file['section']->key(),
                'description' => $file['section']->description(),
                'rows' => $file['rows'],
            ]);
        }

        array_push(
            $lines,
            '',
            self::text('privacy.export.readme.formats'),
            '',
            self::text('privacy.export.readme.excluded'),
            '',
            self::text('privacy.export.readme.availability', ['days' => $this->policy->exportDays()]),
            '',
            self::text('privacy.export.readme.contact'),
        );

        // Fin de línea de Windows: el Bloc de notas lo abre bien.
        return implode("\r\n", $lines)."\r\n";
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/audax-datos-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se ha podido crear {$directory}.");
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
