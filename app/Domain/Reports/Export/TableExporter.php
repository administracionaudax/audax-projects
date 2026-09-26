<?php

namespace App\Domain\Reports\Export;

use App\Support\LocalTime;
use Illuminate\Support\Str;
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
 * Los controladores deciden qué filas y columnas exportar respetando los permisos (D-044) y
 * `view-financials`: este servicio solo escribe.
 */
final class TableExporter
{
    public const array FORMATS = ['xlsx', 'csv'];

    public const int MAX_ROWS = 20000;

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
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     */
    public function download(string $basename, array $headers, iterable $rows, string $format = 'xlsx'): StreamedResponse
    {
        $format = self::format($format);
        $filename = Str::slug($basename, '-', 'es').'-'.LocalTime::todayString().'.'.$format;

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
        $writer->addRow(Row::fromValuesWithStyle($headers, (new Style)->withFontBold(true)));

        $count = 0;
        foreach ($rows as $row) {
            if (++$count > self::MAX_ROWS) {
                break;
            }
            $writer->addRow(Row::fromValues(array_map(fn ($value) => $csv ? self::csvValue($value) : $value, array_values($row))));
        }

        $writer->close();

        return min($count, self::MAX_ROWS);
    }

    private static function csvValue(string|int|float|bool|null $value): string|int|null
    {
        return match (true) {
            is_float($value) => number_format($value, 2, ',', ''),
            is_bool($value) => $value ? 'Sí' : 'No',
            default => $value,
        };
    }
}
