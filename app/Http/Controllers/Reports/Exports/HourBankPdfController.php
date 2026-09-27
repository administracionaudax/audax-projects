<?php

namespace App\Http\Controllers\Reports\Exports;

use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementPdf;
use App\Http\Controllers\Controller;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * PDF de consumo de una bolsa (SPEC §10 «Exportación», D-045; R2):
 * GET /proyectos/{project}/bolsas/{hourBank}/pdf. La bolsa siempre es del proyecto de la URL
 * (scopeBindings: otra da 404). Exige HourBankPolicy::downloadPdf (= viewBreakdown), porque lleva
 * las entradas con la persona. El nombre del fichero lleva el código del proyecto.
 *
 * Por defecto es el PDF para el cliente, sin importes. Con ?importes=1 y view-financials añade el
 * bloque «Datos económicos (uso interno)» y el fichero se marca como «interno», para no enviarlo
 * al cliente por error.
 */
class HourBankPdfController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Project $project, HourBank $hourBank, HourBankStatement $statement, HourBankStatementPdf $pdf): Response
    {
        $this->authorize('downloadPdf', $hourBank);

        /** @var User $user */
        $user = $request->user();
        $data = $statement->build($user, $hourBank, withFinancials: $request->boolean('importes'));
        $content = $pdf->render($data);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::filename($project, $hourBank, $data['financials'] !== null)),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * «ARR-WEB-consumo-bolsa-diseno-2026-09-27.pdf»: código del proyecto (en mayúsculas, como en la
     * app), nombre de la bolsa y fecha, solo con caracteres ASCII seguros. El de uso interno (con
     * importes) lleva «-interno» delante de la fecha.
     */
    public static function filename(Project $project, HourBank $bank, bool $internal = false): string
    {
        $code = (string) preg_replace('/[^A-Z0-9-]+/', '-', Str::upper(Str::ascii($project->code)));

        return trim($code, '-').'-consumo-'.Str::slug($bank->name, '-', 'es').($internal ? '-interno' : '').'-'.LocalTime::todayString().'.pdf';
    }
}
