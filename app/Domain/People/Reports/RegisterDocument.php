<?php

namespace App\Domain\People\Reports;

use App\Domain\Reports\Delivery\Documents\ExportSheet;
use App\Domain\Reports\Delivery\Documents\PdfTable;

/**
 * Un fichero del registro ya preparado (R2, D-351): lo mismo para el PDF (portada, cifras y
 * secciones con sus tablas) y para el Excel y el CSV (cabecera y filas; el Excel puede llevar más
 * hojas). `content` es lo que lleva la huella del contenido: el mismo informe da la misma huella en
 * los tres formatos.
 *
 * @phpstan-import-type Table from PdfTable
 *
 * @phpstan-type Section array{title: string, lead: string|null, table: Table}
 * @phpstan-type Cover array{kicker: string, title: string, subtitle: string, facts: list<array{0: string, 1: string}>, note: string|null}
 */
final readonly class RegisterDocument
{
    /**
     * @param  Cover  $cover
     * @param  list<array{label: string, value: string, detail: string|null}>  $kpis
     * @param  list<Section>  $sections
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|bool|null>>  $rows
     * @param  list<ExportSheet>  $sheets
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $basename,
        public array $cover,
        public array $kpis,
        public array $sections,
        public array $headers,
        public array $rows,
        public array $sheets = [],
        public array $notes = [],
        public bool $landscape = true,
    ) {}

    /**
     * Lo que lleva la huella: el informe, la cabecera y las filas de datos.
     *
     * @return array{kind: string, headers: list<string>, rows: list<list<string|int|float|bool|null>>}
     */
    public function content(): array
    {
        return ['kind' => $this->kind, 'headers' => $this->headers, 'rows' => $this->rows];
    }
}
