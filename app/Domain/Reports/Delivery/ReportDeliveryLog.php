<?php

namespace App\Domain\Reports\Delivery;

use App\Models\User;

/**
 * Auditoría de las salidas de informes (D-139): activity_log con log_name `report-delivery`.
 * - generated: cada fichero que genera ReportFileGenerator (descarga, adjunto de correo o
 *   subida a Google Sheets), con el informe, el formato, los parámetros de la ruta y los filtros,
 * - printed: cada vez que se abre la versión para imprimir.
 * El envío por correo y la subida a Sheets (9.3 y 9.4) añaden sus propios eventos con el destino.
 */
final class ReportDeliveryLog
{
    public const string LOG = 'report-delivery';

    public function generated(User $as, ReportRequest $request, ExportFormat $format, GeneratedReportFile $file): void
    {
        activity(self::LOG)
            ->causedBy($as)
            ->event('generated')
            ->withProperties([
                'kind' => $request->kind->value,
                'format' => $format->value,
                'route_params' => $request->routeParams,
                'query' => $request->query,
                'filename' => $file->filename,
                'title' => $file->title,
            ])
            ->log('report.generated');
    }

    public function printed(User $as, ReportRequest $request, string $title): void
    {
        activity(self::LOG)
            ->causedBy($as)
            ->event('printed')
            ->withProperties([
                'kind' => $request->kind->value,
                'format' => 'print',
                'route_params' => $request->routeParams,
                'query' => $request->query,
                'title' => $title,
            ])
            ->log('report.printed');
    }
}
