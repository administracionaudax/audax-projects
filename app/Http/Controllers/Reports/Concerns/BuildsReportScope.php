<?php

namespace App\Http\Controllers\Reports\Concerns;

use App\Domain\Reports\Export\TableExporter;
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

        return $this->scopeFor($user, $request->query(), $fixed);
    }

    /**
     * El mismo alcance sin petición HTTP: quien mira y la query de la URL (los documentos de
     * exportación, envío y programación de la Fase 9 lo construyen desde un ReportRequest).
     *
     * @param  array<string, mixed>  $query
     * @param  array{userIds?: list<int>, departmentIds?: list<int>, clientIds?: list<int>, projectIds?: list<int>, bankIds?: list<int>, taskTypeIds?: list<int>}  $fixed
     */
    protected function scopeFor(User $user, array $query, array $fixed = []): ReportScope
    {
        $filters = ReportFilters::fromQuery($query);

        return new ReportScope($user, $fixed === [] ? $filters : $filters->with($fixed));
    }

    /**
     * Alcance sin filtros (el mes actual): lo que la persona puede ver, sin acotar por la URL.
     */
    protected function baseReportScope(Request $request): ReportScope
    {
        /** @var User $user */
        $user = $request->user();

        return new ReportScope($user, ReportFilters::fromQuery([]));
    }

    /**
     * ?formato=xlsx|csv pide la exportación (D-045); cualquier otro valor (otro texto, una lista…)
     * muestra la página: nunca un error ni una descarga que no se ha pedido.
     */
    protected function exportFormat(Request $request): ?string
    {
        $format = $request->query('formato');

        return is_string($format) && in_array($format, TableExporter::FORMATS, true) ? $format : null;
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
