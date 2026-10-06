<?php

namespace App\Domain\Reports\Export;

use App\Domain\Reports\Delivery\Documents\ExportSheet;
use App\Support\LocalTime;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de cualquier tabla de los informes a XLSX o CSV (SPEC §10, D-045), en streaming
 * con OpenSpout (MIT): no carga el fichero entero en memoria.
 *
 * - XLSX: los números van como números (horas en decimal: 1,5 = 1 h 30 min) y la cabecera en negrita.
 * - CSV para Excel en español: separador «;», BOM UTF-8 y decimales con coma.
 * - Los textos son SIEMPRE texto, nunca fórmulas (inyección de fórmulas, OWASP «CSV injection»):
 *   descripciones, tareas y nombres los escribe cualquiera. En XLSX van como celdas de texto
 *   (Row::fromValues de OpenSpout convertiría en fórmula cualquier texto que empiece por «=») y en
 *   CSV llevan un apóstrofo delante si empiezan por = + - @, tabulador o retorno de carro.
 * - Límite de filas (D-045: sin cola hasta 20.000): maxRows(). write() nunca pasa de ahí; quien
 *   pueda superarlo lo comprueba antes (la exportación para facturar responde 422, R2).
 * Los controladores deciden qué filas y columnas exportar respetando los permisos (D-044) y
 * `view-financials`: este servicio solo escribe.
 */
final class TableExporter
{
    public const array FORMATS = ['xlsx', 'csv'];

    public const int MAX_ROWS = 20000;

    /** Primeros caracteres con los que una hoja de cálculo interpreta un texto de CSV como fórmula. */
    private const string FORMULA_TRIGGERS = "=+-@\t\r";

    private int $maxRows = self::MAX_ROWS;

    public static function format(?string $requested): string
    {
        return in_array($requested, self::FORMATS, true) ? $requested : 'xlsx';
    }

    /**
     * Horas decimales (redondeadas a 2) a partir de minutos, para las celdas numéricas.
     */
    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /**
     * Importe decimal en string ("1234.50") a número de celda, o null.
     */
    public static function money(?string $amount): ?float
    {
        return $amount === null || $amount === '' ? null : round((float) $amount, 2);
    }

    /**
     * Texto de CSV que una hoja de cálculo no ejecutará como fórmula: con un apóstrofo delante si
     * empieza por = + - @, tabulador o retorno de carro (salvo que sea un número, como «-12.5»).
     */
    public static function neutralize(string $text): string
    {
        return $text !== '' && str_contains(self::FORMULA_TRIGGERS, $text[0]) && ! is_numeric($text) ? "'".$text : $text;
    }

    /**
     * Nombre del fichero: el nombre base en minúsculas y sin acentos, la fecha de hoy y la extensión
     * («informe-de-direccion-clientes-2026-09-25.xlsx»). También lo usa ReportFileGenerator (D-139).
     */
    public static function filename(string $basename, string $format): string
    {
        return Str::slug($basename, '-', 'es').'-'.LocalTime::todayString().'.'.self::format($format);
    }

    /**
     * Copia con otro límite de filas (tests del límite sin generar 20.000 entradas).
     */
    public function withMaxRows(int $rows): self
    {
        $copy = clone $this;
        $copy->maxRows = max(1, $rows);

        return $copy;
    }

    /**
     * Filas de datos que admite una exportación, incluida la de totales si la hay (sin la cabecera).
     */
    public function maxRows(): int
    {
        return $this->maxRows;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     */
    public function download(string $basename, array $headers, iterable $rows, string $format = 'xlsx'): StreamedResponse
    {
        $format = self::format($format);
        $filename = self::filename($basename, $format);

        return response()->streamDownload(function () use ($headers, $rows, $format): void {
            $this->write('php://output', $headers, $rows, $format);
        }, $filename, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Escribe en un fichero o flujo (también lo usan los tests).
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     */
    public function write(string $path, array $headers, iterable $rows, string $format = 'xlsx'): int
    {
        $csv = self::format($format) === 'csv';
        $writer = $csv ? new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true)) : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(self::row($headers, $csv, (new Style)->withFontBold(true)));

        $count = 0;
        foreach ($rows as $row) {
            if (++$count > $this->maxRows) {
                break;
            }
            $writer->addRow(self::row(array_values($row), $csv));
        }

        $writer->close();

        return min($count, $this->maxRows);
    }

    /**
     * Libro de Excel con varias hojas (D-240), cada una con su cabecera en negrita y como mucho
     * maxRows() filas. Los nombres de hoja se limpian (31 caracteres, sin \ / ? * [ ] :) y no se
     * repiten.
     *
     * @param  list<ExportSheet>  $sheets
     */
    public function writeWorkbook(string $path, array $sheets): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $used = [];

        foreach ($sheets as $index => $sheet) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName(self::sheetName($sheet->name, $index, $used));
            $writer->addRow(self::row($sheet->headers, false, (new Style)->withFontBold(true)));

            $count = 0;
            foreach ($sheet->rows as $row) {
                if (++$count > $this->maxRows) {
                    break;
                }
                $writer->addRow(self::row(array_values($row), false));
            }
        }

        $writer->close();
    }

    /**
     * @param  array<string, true>  $used
     */
    private static function sheetName(string $name, int $index, array &$used): string
    {
        $clean = trim(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name), 0, 31));
        $clean = $clean === '' ? 'Hoja '.($index + 1) : $clean;

        $candidate = $clean;
        for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
            $candidate = mb_substr($clean, 0, 31 - strlen((string) $n) - 1).' '.$n;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * Fila con celdas explícitas: nunca Row::fromValues, que convierte en fórmula el texto que
     * empieza por «=».
     *
     * @param  list<string|int|float|bool|null>  $values
     */
    private static function row(array $values, bool $csv, ?Style $style = null): Row
    {
        return new Row(array_map(
            fn (string|int|float|bool|null $value): Cell => $csv ? self::csvCell($value, $style) : self::xlsxCell($value, $style),
            $values,
        ));
    }

    private static function xlsxCell(string|int|float|bool|null $value, ?Style $style): Cell
    {
        return match (true) {
            $value === null, $value === '' => new EmptyCell(null, $style),
            is_bool($value) => new BooleanCell($value, $style),
            is_string($value) => new StringCell($value, $style),
            default => new NumericCell($value, $style),
        };
    }

    /**
     * CSV para Excel en español: decimales con coma, «Sí» o «No» y el texto neutralizado.
     */
    private static function csvCell(string|int|float|bool|null $value, ?Style $style): Cell
    {
        return match (true) {
            $value === null, $value === '' => new EmptyCell(null, $style),
            is_float($value) => new StringCell(number_format($value, 2, ',', ''), $style),
            is_bool($value) => new StringCell($value ? 'Sí' : 'No', $style),
            is_string($value) => new StringCell(self::neutralize($value), $style),
            default => new NumericCell($value, $style),
        };
    }
}
