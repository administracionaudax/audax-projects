<?php

namespace App\Domain\Import\WeeklySync\Dump;

/**
 * Resultado de un volcado: filas por tabla (null si la tabla no existía en el origen), ficheros
 * descargados y su tamaño, los que no se encontraron y el tiempo.
 */
final readonly class DumpResult
{
    /**
     * @param  array<string, int|null>  $tables
     * @param  list<string>  $missing  "bucket/ruta"
     */
    public function __construct(
        public array $tables,
        public int $files,
        public int $bytes,
        public array $missing,
        public float $seconds,
    ) {}
}
