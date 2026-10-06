<?php

namespace App\Domain\Reports\Delivery\Documents;

/**
 * Tabla de un informe para Excel o CSV (TableExporter): el nombre base del fichero (sin fecha ni
 * extensión, TableExporter::filename), la cabecera y las filas (puede ser un generador: las
 * exportaciones grandes no se cargan enteras en memoria), y el título legible del informe
 * (ReportFileGenerator::title), que el documento ya conoce sin más consultas.
 * Con $sheets (D-240), el Excel es un libro con esas hojas; el CSV, que solo tiene una tabla,
 * sigue siendo la de $headers y $rows.
 */
final readonly class ExportTable
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     * @param  list<ExportSheet>  $sheets
     */
    public function __construct(
        public string $basename,
        public array $headers,
        public iterable $rows,
        public string $title,
        public array $sheets = [],
    ) {}
}
