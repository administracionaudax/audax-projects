<?php

namespace App\Domain\Reports\Delivery;

use App\Models\User;
use RuntimeException;

/**
 * Respaldo mientras no está el generador real (entrega 9.2): ReportDeliveryServiceProvider lo
 * registra con bindIf, así que cualquier binding de ReportFileGenerator lo sustituye. Generar un
 * informe falla con un error claro (el envío queda «Fallido» con este mensaje), nunca en silencio.
 */
final class UnavailableReportFileGenerator implements ReportFileGenerator
{
    public function generate(ReportRequest $request, ExportFormat $format, User $as): GeneratedReportFile
    {
        throw new RuntimeException(self::message());
    }

    public function title(ReportRequest $request, User $as): string
    {
        throw new RuntimeException(self::message());
    }

    public static function message(): string
    {
        return 'El generador de informes (ReportFileGenerator, entrega 9.2) no está disponible.';
    }
}
