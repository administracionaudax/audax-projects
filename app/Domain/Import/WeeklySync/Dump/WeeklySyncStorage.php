<?php

namespace App\Domain\Import\WeeklySync\Dump;

/**
 * Descarga de los ficheros del Storage de WeeklySync (SupabaseStorage o, en los tests, Http::fake).
 */
interface WeeklySyncStorage
{
    /**
     * Descarga bucket/ruta en $target. Devuelve false si el fichero no existe en el origen.
     *
     * @throws StorageDownloadFailed si el origen responde con otro error
     */
    public function download(string $bucket, string $path, string $target): bool;
}
