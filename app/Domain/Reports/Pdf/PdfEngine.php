<?php

namespace App\Domain\Reports\Pdf;

/**
 * Motor que convierte el HTML de un informe en su fichero «PDF» (D-140), según
 * services.reports_pdf.driver: `gotenberg` (el PDF de verdad) o `html` (el HTML tal cual, para los
 * tests y el desarrollo local sin Docker).
 */
interface PdfEngine
{
    /**
     * @throws PdfConversionFailed
     */
    public function render(string $html): string;

    /** Extensión del fichero que genera: pdf, o html con el motor html. */
    public function extension(): string;

    public function mime(): string;
}
