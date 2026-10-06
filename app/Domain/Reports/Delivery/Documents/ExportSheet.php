<?php

namespace App\Domain\Reports\Delivery\Documents;

/**
 * Una hoja de un libro de Excel de varias hojas (ExportTable::$sheets, D-240): su nombre (como
 * mucho 31 caracteres, sin \ / ? * [ ] :), la cabecera y las filas (puede ser un generador).
 */
final readonly class ExportSheet
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     */
    public function __construct(
        public string $name,
        public array $headers,
        public iterable $rows,
    ) {}
}
