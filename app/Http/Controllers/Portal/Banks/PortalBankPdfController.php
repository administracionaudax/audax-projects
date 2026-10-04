<?php

namespace App\Http\Controllers\Portal\Banks;

use App\Domain\Portal\PortalScope;
use App\Domain\Reports\Delivery\Documents\HourBankDocument;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementView;
use App\Domain\Reports\Pdf\PdfEngine;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * PDF de consumo de una bolsa para el cliente (SPEC §11, D-066): GET /portal/bolsas/{bolsa}/pdf.
 * Es el PDF de la Fase 2 en modo portal (HourBankStatement::forPortal): solo las horas que ve el
 * cliente según su ajuste, las personas como las ve él y NUNCA importes (no hay parámetro que los
 * añada: ?importes=1 no cambia nada). Sus cifras son las de PortalBankFigures, las de la barra y el
 * listado del portal, sin el bloque «sin aprobar» (D-095). Una bolsa de otro cliente da 404.
 * Limitado por minuto (routes/portal/banks.php). Desde la Fase 9 (D-140), con la hoja de
 * documentos de Audax (HourBankStatementView) y el motor de PDF de los informes (Gotenberg).
 */
class PortalBankPdfController extends Controller
{
    public function __invoke(Request $request, int $bank, HourBankStatement $statement, ReportHtml $html, PdfEngine $engine): Response
    {
        /** @var User $user */
        $user = $request->user();
        $scope = PortalScope::for($user);

        $hourBank = $scope->hourBanks()
            ->with(['project' => fn ($project) => $project->select(['id', 'code', 'name'])])
            ->find($bank, PortalBankData::COLUMNS);
        abort_if($hourBank === null, 404);

        $pdf = HourBankStatementView::make($statement->forPortal($scope, $hourBank), '', HourBankDocument::filenameFor($hourBank->project, $hourBank));
        $content = $engine->render($html->render($pdf));

        return response($content, 200, [
            'Content-Type' => $engine->mime(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->filename.'.'.$engine->extension()),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
