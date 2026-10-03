<?php

namespace App\Domain\Reports\Pdf;

use RuntimeException;

/**
 * El HTML de un informe no se ha podido convertir a PDF (Gotenberg caído, lento o con error). El
 * mensaje dice qué ha pasado, para el registro y para quien programa un envío (D-140).
 */
final class PdfConversionFailed extends RuntimeException {}
