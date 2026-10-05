<?php

namespace App\Domain\Import\WeeklySync;

use App\Domain\Tasks\AttachmentStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copia de los ficheros del volcado al disco privado de Audax (`local`), con rutas fijas para que
 * repetir la importación no duplique nada: si el destino ya tiene el mismo contenido (tamaño y
 * sha256), no se vuelve a copiar. En --dry-run solo se comprueban (que estén y que cuadren con el
 * manifiesto) y se cuentan.
 */
final class WeeklySyncFiles
{
    public const string DISK = AttachmentStorage::DISK;

    /** @var array<string, true> destinos escritos en esta ejecución */
    private array $written = [];

    public function __construct(
        private readonly WeeklySyncDump $dump,
        private readonly WeeklySyncImportReport $report,
        private readonly bool $dryRun,
    ) {}

    /**
     * @return array{disk: string, path: string, size: int, mime: string}|null null si el volcado no lo trae
     */
    public function copy(string $bucket, ?string $source, string $target): ?array
    {
        if ($source === null) {
            return null;
        }

        $file = $this->dump->file($bucket, $source);

        if ($file === null) {
            $this->report->filesMissing++;
            $this->report->warn("Fichero que no está en el volcado: {$bucket}/{$source}.");

            return null;
        }

        $mime = self::mime($file['path']);
        $result = ['disk' => self::DISK, 'path' => $target, 'size' => $file['size'], 'mime' => $mime];

        if (isset($this->written[$target])) {
            return $result;
        }
        $this->written[$target] = true;

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($target) && $disk->size($target) === $file['size'] && hash_file('sha256', $disk->path($target)) === $file['sha256']) {
            $this->report->filesUnchanged++;

            return $result;
        }

        $this->report->filesCopied++;
        $this->report->bytesCopied += $file['size'];

        if ($this->dryRun) {
            return $result;
        }

        $stream = fopen($file['path'], 'rb');

        if ($stream === false || ! $disk->writeStream($target, $stream)) {
            throw new RuntimeException("No se ha podido copiar {$bucket}/{$source} a {$target}.");
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $result;
    }

    /**
     * Extensión del fichero de origen (en minúsculas, solo letras y números) o la que se indique.
     */
    public static function extension(string $source, string $fallback): string
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : $fallback;
    }

    private static function mime(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
