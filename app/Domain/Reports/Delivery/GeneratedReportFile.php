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
}
