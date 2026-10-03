<?php

namespace App\Domain\Reports\Delivery\Documents;

/**
 * Tabla del PDF (partial reports.pdf.partials.table): columnas con su alineación, filas de celdas
 * ya formateadas y, si la hay, la fila de totales (con fondo gris). Una celda es un texto o
 * ['text' => …, 'class' => …] (p. ej. «overage» para el exceso).
 *
 * @phpstan-type Cell string|int|float|null|array{text: string, class: string}
 * @phpstan-type Table array{columns: list<array{label: string, num: bool}>, rows: list<list<Cell>>, sum: list<Cell>|null, compact: bool, empty: string|null}
 */
final class PdfTable
{
    /**
     * @param  list<array{0: string, 1?: bool}>  $columns  etiqueta y si es numérica (a la derecha)
     * @param  list<list<Cell>>  $rows
     * @param  list<Cell>|null  $sum
     * @return Table
     */
    public static function make(array $columns, array $rows, ?array $sum = null, bool $compact = false, ?string $empty = null): array
    {
        return [
            'columns' => array_map(fn (array $column): array => ['label' => $column[0], 'num' => $column[1] ?? false], $columns),
            'rows' => $rows,
            'sum' => $sum,
            'compact' => $compact,
            'empty' => $empty,
        ];
    }

    /**
     * Celda con una clase (overage, muted…).
     *
     * @return array{text: string, class: string}
     */
    public static function cell(string $text, string $class): array
    {
        return ['text' => $text, 'class' => $class];
    }
}
