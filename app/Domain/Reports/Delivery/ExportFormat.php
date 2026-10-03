<?php

namespace App\Domain\Reports\Delivery;

/**
 * Formatos de fichero de un informe (Fase 9, D-139). Google Sheets e Imprimir no son ficheros:
 * Sheets sube el XLSX a Drive convertido (D-142) e Imprimir abre el mismo HTML que el PDF (D-140).
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mime(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
            self::Pdf => 'application/pdf',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel',
            self::Csv => 'CSV',
            self::Pdf => 'PDF',
        };
    }
}
