<?php

namespace App\Domain\Reports\Pdf;

/**
 * REPORTS_PDF_DRIVER=html: el «PDF» es el propio HTML del informe (el mismo que convertiría
 * Gotenberg). Para los tests y el desarrollo local sin Docker; nunca en el servidor.
 */
final class HtmlEngine implements PdfEngine
{
    public function render(string $html): string
    {
        return $html;
    }

    public function extension(): string
    {
        return 'html';
    }

    public function mime(): string
    {
        return 'text/html; charset=UTF-8';
    }
}
