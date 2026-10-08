<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingAccess;
use App\Domain\Billing\BillingPanel;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Projects\ResourceData;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Facturación del proyecto (Fase 12, D-392): /proyectos/{id}/facturacion con su «Vendido
 * frente a real» (bolsas, precio cerrado, fee u horas) y, con view-billing, sus facturas de Holded.
 * Quién: BillingAccess::viewsProject (un gestor, sus proyectos; nunca un proyecto interno).
 */
class ProjectBillingController extends Controller
{
    use BuildsReportScope;

    public function __invoke(Request $request, Project $project, BillingPanel $panel): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(BillingAccess::viewsProject($user, $project), 403);

        $project->load(['client:id,name', 'owner' => fn ($owner) => $owner->select(ProjectController::USER_SUMMARY_COLUMNS)]);
        $query = SoldVsActualQuery::fromQuery(SoldVsActualController::defaultPeriod($request->query()));
        $filters = $this->filterProps(new ReportScope($user, $query->filters));
        $filters['query'] = $query->filters->toQuery();
        $filters['can_see_financials'] = $user->can('view-billing');

        return Inertia::render('projects/billing', [
            'project' => ResourceData::of(ProjectResource::make($project), $request),
            'canManage' => $user->can('update', $project),
            'filters' => $filters,
            'panel' => $panel->forProject($project, $user, $query),
        ]);
    }
}
