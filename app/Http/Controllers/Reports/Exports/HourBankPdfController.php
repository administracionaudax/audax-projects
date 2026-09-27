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
 */
class HourBankPdfController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Project $project, HourBank $hourBank, HourBankStatement $statement, HourBankStatementPdf $pdf): Response
    {
        $this->authorize('downloadPdf', $hourBank);

        /** @var User $user */
        $user = $request->user();
        $content = $pdf->render($statement->build($user, $hourBank));

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::filename($project, $hourBank)),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * «ARR-WEB-consumo-bolsa-diseno-2026-09-27.pdf»: código del proyecto (en mayúsculas, como en la
     * app), nombre de la bolsa y fecha, solo con caracteres ASCII seguros.
     */
    public static function filename(Project $project, HourBank $bank): string
    {
        $code = (string) preg_replace('/[^A-Z0-9-]+/', '-', Str::upper(Str::ascii($project->code)));

        return trim($code, '-').'-consumo-'.Str::slug($bank->name, '-', 'es').'-'.LocalTime::todayString().'.pdf';
    }
}
