<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\InvoicingQuery;
use App\Domain\Reports\Delivery\Documents\InvoicingDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\ReportScope;
use App\Enums\BillingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Ventas, el informe de facturación (D-400 y D-405): /facturacion/ventas, con el periodo y los clientes de la barra de
 * los informes más el servicio (?servicio[]=), y ?formato=xlsx|csv|pdf|imprimir (ExportsReports).
 * Sin periodo en la URL, el año en curso comparado con el anterior. Quién: view-billing (en la ruta).
 */
class InvoicingReportController extends Controller
{
    use BuildsReportScope, ExportsReports;

    public function __invoke(Request $request, InvoicingDocument $document): Response|SymfonyResponse
    {
        $export = $this->exportResponse($request, ReportKind::Invoicing);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $query = InvoicingQuery::fromQuery($request->query());
        $filters = $this->filterProps(new ReportScope($user, $query->filters));
        $filters['query'] = $query->toQuery();
        // La comparación de este informe es con el mismo periodo del año anterior.
        $filters['comparison'] = $query->compares()
            ? ['from' => $query->previousFrom()->toDateString(), 'to' => $query->previousTo()->toDateString()]
            : null;

        return Inertia::render('billing/report', [
            'filters' => $filters,
            'services' => array_map(fn (BillingService $service): string => $service->value, BillingService::cases()),
            'report' => $document->data($query),
            'report_request' => $this->reportRequestProp(ReportKind::Invoicing, [], $query->toQuery()),
        ]);
    }
}
