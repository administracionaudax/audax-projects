<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ComparisonPeriod;
use App\Domain\Reports\Delivery\Documents\ClientDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Informe de un cliente (SPEC §10.2; R2): horas por proyecto y por mes (o por semana si el periodo
 * es corto), resumen por proyecto con rentabilidad, bolsas con su consumo (dentro y exceso por
 * separado) e histórico de renovaciones.
 *
 * Quién (D-044, ClientPolicy::viewReport): admins y responsables, todo el cliente; un gestor, solo
 * los proyectos del cliente que gestiona (se fuerza el filtro de proyecto). Las horas, además,
 * pasan por ReportScope (un responsable ve las de su equipo y las de los proyectos que gestiona).
 * Los importes, solo con view-financials. Exporta con ?formato=xlsx|csv&tabla=proyectos|meses|bolsas
 * y, entero, con ?formato=pdf o imprimir (Fase 9: ClientDocument, D-139 y D-140).
 */
class ClientReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope, ExportsReports;

    public const array TABLES = ClientDocument::TABLES;

    /** Periodos de más días se agrupan por mes; los demás, por semana. */
    public const int MONTHLY_FROM_DAYS = ClientDocument::MONTHLY_FROM_DAYS;

    public function __invoke(
        Request $request,
        Client $client,
        Metrics $metrics,
        ReportCache $cache,
        ClientDocument $document,
    ): Response|SymfonyResponse {
        $this->authorize('viewReport', $client);

        $export = $this->exportResponse($request, ReportKind::Client);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $context = $document->context($user, $client, $request->query());
        $scope = $context['scope'];
        $data = $document->data($context);

        // Periodo en curso: comparación «al mismo punto», como el resto de dashboards (D-079).
        $comparison = ComparisonPeriod::summary($scope, $metrics, $cache, 'r2.client.'.$client->id.'.summary', withCapacity: false, everyAssignee: $context['every_assignee']);
        $filters = ComparisonPeriod::withRange($this->filterProps(new ReportScope($user, $context['url_filters'])), $comparison['range']);

        return Inertia::render('reports/client', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active],
            'filters' => $filters,
            'report_request' => $this->reportRequestProp(ReportKind::Client, ['client' => $client->id], $filters['query']),
            'scope' => [
                'projects_only' => $context['limited'],
                'team_only' => ! $user->isAdmin(),
            ],
            'summary' => $data['summary'],
            'comparison' => $comparison['summary'],
            'banked' => $data['banked'],
            'projects' => $data['projects'],
            'timeline' => $data['timeline'],
            'banks' => $data['banks'],
            'history' => $data['history'],
        ]);
    }
}
