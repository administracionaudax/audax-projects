<?php

namespace App\Http\Controllers\Portal\Banks;

use App\Domain\Portal\PortalScope;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementPdf;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Exports\HourBankPdfController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * PDF de consumo de una bolsa para el cliente (SPEC §11, D-066): GET /portal/bolsas/{bolsa}/pdf.
 * Es el PDF de la Fase 2 en modo portal (HourBankStatement::forPortal): solo las horas que ve el
 * cliente según su ajuste, las personas como las ve él y NUNCA importes (no hay parámetro que los
 * añada: ?importes=1 no cambia nada). Sus cifras son las de PortalBankFigures, las de la barra y el
 * listado del portal. Una bolsa de otro cliente da 404. Limitado por minuto (routes/portal/banks.php).
 */
class PortalBankPdfController extends Controller
{
    public function __invoke(Request $request, int $bank, HourBankStatement $statement, HourBankStatementPdf $pdf): Response
    {
        /** @var User $user */
        $user = $request->user();
        $scope = PortalScope::for($user);

        $hourBank = $scope->hourBanks()
            ->with(['project' => fn ($project) => $project->select(['id', 'code', 'name'])])
            ->find($bank, PortalBankData::COLUMNS);
        abort_if($hourBank === null, 404);

        $content = $pdf->render($statement->forPortal($scope, $hourBank));

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, HourBankPdfController::filename($hourBank->project, $hourBank)),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
