<?php

namespace App\Domain\Reports\Delivery;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Genera el fichero de un informe con los permisos de $as (D-139): el mismo contenido que verías
 * en pantalla con esos filtros. Lo usan la descarga (?formato=), el envío por correo, los envíos
 * programados y Google Sheets (que sube el XLSX). Implementación: entrega 9.2.
 */
interface ReportFileGenerator
{
    /**
     * @throws AuthorizationException si $as ya no puede ver ese informe
     */
    public function generate(ReportRequest $request, ExportFormat $format, User $as): GeneratedReportFile;

    /**
     * Título legible del informe con sus filtros, p. ej. «Informe de cliente · Montó · septiembre 2026».
     */
    public function title(ReportRequest $request, User $as): string;
}
