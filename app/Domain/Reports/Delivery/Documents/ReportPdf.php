<?php

namespace App\Domain\Reports\Delivery\Documents;

/**
 * Documento del PDF y de la impresión de un informe (D-140): la vista Blade
 * (resources/views/reports/pdf/*.blade.php, que extiende reports.pdf.layout), su título, el nombre
 * del fichero sin extensión y los datos ya formateados (PdfFormat). `cover` lleva la portada.
 */
final readonly class ReportPdf
{
    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $view,
        public string $title,
        public string $filename,
        public array $data,
        public bool $landscape = false,
    ) {}
}
