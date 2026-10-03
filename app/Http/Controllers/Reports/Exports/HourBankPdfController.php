<?php

namespace App\Http\Controllers\Reports\Exports;

use App\Domain\Reports\Delivery\Documents\HourBankDocument;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de consumo de una bolsa (SPEC §10 «Exportación», D-045; R2):
 * GET /proyectos/{project}/bolsas/{hourBank}/pdf. La bolsa siempre es del proyecto de la URL
 * (scopeBindings: otra da 404). Exige HourBankPolicy::downloadPdf (= viewBreakdown), porque lleva
 * las entradas con la persona. El nombre del fichero lleva el código del proyecto.
 *
 * Por defecto es el PDF para el cliente, sin importes. Con ?importes=1 y view-financials añade el
 * bloque «Datos económicos (uso interno)» y el fichero se marca como «interno», para no enviarlo
 * al cliente por error. Desde la Fase 9 (D-140) sale del HTML con la hoja de documentos de Audax
 * convertido por Gotenberg (HourBankDocument y ReportFileGenerator); ?formato=imprimir abre la
 * versión para imprimir y ?formato=xlsx|csv, el detalle de las horas.
 */
class HourBankPdfController extends Controller
{
    use AuthorizesRequests, ExportsReports;

    public function __invoke(Request $request, Project $project, HourBank $hourBank): Response
    {
        $this->authorize('downloadPdf', $hourBank);

        return $this->exportResponse($request, ReportKind::HourBank, ['project' => $project->id, 'hourBank' => $hourBank->id], ExportFormat::Pdf) ?? abort(404);
    }

    /**
     * «ARR-WEB-consumo-bolsa-diseno-2026-09-27.pdf» (HourBankDocument::filenameFor).
     */
    public static function filename(Project $project, HourBank $bank, bool $internal = false): string
    {
        return HourBankDocument::filenameFor($project, $bank, $internal).'.pdf';
    }
}
