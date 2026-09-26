<?php

namespace App\Http\Controllers\Reports\Concerns;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Contrato de los controladores de informes (Fase 2): el alcance sale SIEMPRE de la URL
 * (ReportFilters) y de quien mira (ReportScope, D-044). Los dashboards fijos (un cliente, un
 * proyecto…) añaden su filtro con $fixed.
 */
trait BuildsReportScope
{
    /**
     * @param  array{userIds?: list<int>, departmentIds?: list<int>, clientIds?: list<int>, projectIds?: list<int>, bankIds?: list<int>, taskTypeIds?: list<int>}  $fixed
     */
    protected function reportScope(Request $request, array $fixed = []): ReportScope
    {
        /** @var User $user */
        $user = $request->user();
        $filters = ReportFilters::fromQuery($request->query());

        return new ReportScope($user, $fixed === [] ? $filters : $filters->with($fixed));
    }

    /**
     * Props comunes de la barra de filtros (contrato con resources/js/types/reports.ts: ReportFiltersProps).
     *
     * @return array<string, mixed>
     */
    protected function filterProps(ReportScope $scope): array
    {
        $f = $scope->filters;

        return [
            'query' => $f->toQuery(),
            'period' => $f->period->value,
            'from' => $f->from->toDateString(),
            'to' => $f->to->toDateString(),
            'compare' => $f->compare,
            'previous' => $f->shifted(-1)->toQuery(),
            'next' => $f->shifted(1)->toQuery(),
            'comparison' => $f->compare ? ['from' => $f->comparison()->from->toDateString(), 'to' => $f->comparison()->to->toDateString()] : null,
            'can_see_financials' => $scope->canSeeFinancials(),
        ];
    }
}
