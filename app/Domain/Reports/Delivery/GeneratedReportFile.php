<?php

namespace App\Domain\Reports\Delivery;

/**
 * Fichero de informe ya generado en disco local (temporal). Quien lo pide lo borra al terminar
 * (adjunto de correo, subida a Drive o descarga).
 */
final readonly class GeneratedReportFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public ExportFormat $format,
        public string $title,
    ) {}

    /**
     * Tipo MIME del fichero tal como está en disco: el del formato, salvo el «PDF» del motor html
     * (REPORTS_PDF_DRIVER=html, tests y local), que es el HTML del informe (9.2, D-140).
     */
    public function mime(): string
    {
        return match (true) {
            str_ends_with($this->filename, '.html') => 'text/html; charset=UTF-8',
            $this->format === ExportFormat::Csv => 'text/csv; charset=UTF-8',
            default => $this->format->mime(),
        };
    }
}
