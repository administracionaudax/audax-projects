<?php

namespace App\Http\Controllers\Reports\Exports;

use App\Domain\Reports\Delivery\Documents\BillingDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\ReportFilters;
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
 * Exportación de horas para facturar (SPEC §10 «Exportación», D-045; R2): /facturacion/horas-para-facturar
 * (antes /informes/facturacion, que redirige con un 301, D-401). No exige el módulo `billing` (D-402).
 * Un cliente (obligatorio, ?cliente[]=id) y un periodo con los filtros globales. La página muestra
 * el resumen por proyecto y bolsa (dentro, exceso, facturables y pendientes de aprobar; tarifas e
 * importes con view-financials) y ?formato=xlsx|csv descarga el detalle de cada entrada.
 *
 * Quién (ClientPolicy::viewBilling): admins y quien tenga view-financials. Las horas pasan por
 * ReportScope (D-044): un no admin con view-financials solo exporta las que puede ver.
 *
 * Límite (D-045: sin cola hasta 20.000 filas): si las entradas no caben en el fichero (con la fila
 * de totales), la exportación responde 422 en lugar de recortarlo, y la página avisa antes.
 * ?formato=pdf o imprimir: el resumen y el detalle (Fase 9: BillingDocument, D-139 y D-140).
 */
class BillingReportController extends Controller
{
    use AuthorizesRequests, BuildsReportScope, ExportsReports;

    public function __invoke(Request $request, BillingDocument $document, TableExporter $exporter): Response|SymfonyResponse
    {
        $this->authorize('viewBilling', Client::class);

        // Sin cliente, la exportación responde 422 (BillingDocument); con cliente, también si no cabe.
        $export = $this->exportResponse($request, ReportKind::Billing);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        // Facturación no compara con el periodo anterior (INT-05): sin comparar=1 en la barra ni en sus enlaces.
        $urlFilters = ReportFilters::fromQuery($request->query())->withoutComparison();
        $client = $urlFilters->clientIds === [] ? null : Client::query()->find($urlFilters->clientIds[0], ['id', 'name', 'is_active']);

        if ($client === null) {
            return $this->page($user, $urlFilters, null, null, $exporter->maxRows() - 1);
        }

        $scope = $this->reportScope($request, ['clientIds' => [$client->id]]);

        return $this->page($user, $urlFilters->with(['clientIds' => [$client->id]]), $client, $document->summary($scope, $client), $exporter->maxRows() - 1);
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function page(User $user, ReportFilters $filters, ?Client $client, ?array $summary, int $exportLimit): Response
    {
        $props = $this->filterProps(new ReportScope($user, $filters));

        return Inertia::render('billing/hours', [
            'filters' => $props,
            'report_request' => $client === null ? null : $this->reportRequestProp(ReportKind::Billing, [], $props['query']),
            'client' => $client === null ? null : ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active],
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (Client $option): array => ['id' => $option->id, 'name' => $option->name, 'is_active' => $option->is_active])
                ->values()->all(),
            'summary' => $summary,
            'scope' => ['team_only' => ! $user->isAdmin()],
            // Entradas que caben en la exportación (sin la fila de totales).
            'export_limit' => $exportLimit,
            // El informe del cliente tiene sus propios permisos (ClientPolicy::viewReport).
            'can' => ['viewReport' => $client !== null && $user->can('viewReport', $client)],
        ]);
    }
}
