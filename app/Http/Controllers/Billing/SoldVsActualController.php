<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingAccess;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Reports\Delivery\Documents\SoldVsActualDocument;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\ReportScope;
use App\Enums\SaleKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * «Vendido frente a real» (Fase 12, F1; D-390): /facturacion/vendido-frente-a-real (antes en
 * /informes, que redirige, D-401), con los filtros de
 * los informes (periodo y cliente) más el tipo de venta y el responsable, y ?formato=xlsx|csv|pdf|
 * imprimir (ExportsReports). Quién: view-sold-vs-actual (en la ruta); importes con view-billing.
 */
class SoldVsActualController extends Controller
{
    use BuildsReportScope, ExportsReports;

    public function __invoke(Request $request, SoldVsActualDocument $document): Response|SymfonyResponse
    {
        $export = $this->exportResponse($request, ReportKind::SoldVsActual);
        if ($export !== null) {
            return $export;
        }

        /** @var User $user */
        $user = $request->user();
        $query = SoldVsActualQuery::fromQuery(self::defaultPeriod($request->query()));
        $filters = $this->filterProps(new ReportScope($user, $query->filters));
        $filters['query'] = $query->toQuery();
        $filters['can_see_financials'] = $user->can('view-billing');

        return Inertia::render('billing/sold-vs-actual', [
            'filters' => $filters,
            'kinds' => array_map(fn (SaleKind $kind): string => $kind->value, $query->kinds),
            'manager' => $query->managerId,
            'managers' => self::managers($user),
            'report' => $document->data($query, $user),
            'report_request' => $this->reportRequestProp(ReportKind::SoldVsActual, [], $query->toQuery()),
            'scope' => ['own_projects' => BillingAccess::projectScope($user) !== null],
        ]);
    }

    /**
     * Sin periodo en la URL, el año en curso: bolsas y fees se leen mejor a lo largo del año.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function defaultPeriod(array $query): array
    {
        return isset($query['periodo']) ? $query : ['periodo' => 'anio', ...$query];
    }

    /**
     * Gestores principales de los proyectos que ve (para el filtro «Responsable»).
     *
     * @return list<array{id: int, name: string}>
     */
    public static function managers(User $user): array
    {
        $scope = BillingAccess::projectScope($user);

        return array_values(User::query()
            ->whereIn('id', Project::query()->whereNotNull('client_id')
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope === [] ? [0] : $scope))
                ->select('owner_user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $manager): array => ['id' => $manager->id, 'name' => $manager->name])
            ->values()->all());
    }
}
