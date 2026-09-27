<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Time\Week;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Http\Controllers\Reports\R1\BuildsDashboards;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard de una persona (/informes/personas/{user}, SPEC §10.5, D-044): la propia persona,
 * quien la supervisa (responsable de su departamento) o un admin; el resto, 403.
 * - capacidad, imputadas, facturables, ocupación, facturabilidad y precisión de estimación (con
 *   variación si comparar=1); ingreso, coste y margen solo con view-financials,
 * - reparto por cliente, proyecto y tipo de tarea y calendario de calor diario del periodo (con
 *   los filtros de la URL),
 * - días sin imputar: con capacidad y sin ninguna hora (de ningún cliente ni proyecto: aquí no
 *   cuentan los filtros), hasta ayer y nunca antes de que cuente su capacidad (su alta o su
 *   primer horario, Metrics::capacityByDate),
 * - ?formato=xlsx|csv exporta el detalle diario.
 *
 * @phpstan-type DayPoint array{bucket: string, logged_minutes: int, billable_minutes: int, capacity_minutes: int, income: string|null}
 */
class PersonReportController extends Controller
{
    use BuildsDashboards, BuildsReportScope;

    public const int TOP = 8;

    public function __invoke(
        Request $request,
        User $user,
        Metrics $metrics,
        ReportCache $cache,
        TableExporter $exporter,
    ): Response|StreamedResponse {
        Gate::authorize('viewReport', $user);

        /** @var User $viewer */
        $viewer = $request->user();
        $scope = $this->reportScope($request, ['userIds' => [$user->id], 'departmentIds' => []]);
        $financials = $scope->canSeeFinancials();

        $data = $cache->remember($scope, 'r1.person', fn (): array => [
            'days' => $metrics->series($scope, Dimension::Day),
            'clients' => $this->withMargin($metrics->breakdown($scope, Dimension::Client)),
            'projects' => $this->withMargin($metrics->breakdown($scope, Dimension::Project)),
            'types' => $this->withMargin($metrics->breakdown($scope, Dimension::TaskType)),
        ]);
        ['summary' => $summary, 'comparison' => $comparison] = $this->summaries($scope, $metrics, $cache);

        $format = $this->exportFormat($request);
        if ($format !== null) {
            [$headers, $rows] = $this->daysTable($data['days'], $financials);

            return $exporter->download(__('reports.r1.exports.person', ['person' => $user->name]), $headers, $rows, $format);
        }

        $user->loadMissing('department:id,name');

        return Inertia::render('reports/person', [
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => $user->is_active,
                'department' => $user->department === null ? null : [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                    'can_view' => Gate::forUser($viewer)->allows('viewReport', $user->department),
                ],
            ],
            'is_self' => $viewer->id === $user->id,
            'filters' => $this->filterPropsWithout($scope, ['persona', 'departamento']),
            'summary' => $summary,
            'comparison' => $comparison,
            'clients' => $this->top($data['clients'], self::TOP),
            'projects' => $this->top($data['projects'], self::TOP),
            'types' => $this->top($data['types'], self::TOP),
            'days' => array_map(fn (array $day): array => ['date' => $day['bucket'], 'minutes' => $day['logged_minutes']], $data['days']),
            'unlogged' => $this->unloggedDays($this->allHours($scope, $user, $data['days'], $metrics, $cache)),
        ]);
    }

    /**
     * Horas por día de la persona en el periodo sin los demás filtros (para los días sin imputar
     * cuenta cualquier hora). Si la URL no filtra nada más, son las mismas que las del calendario.
     *
     * @param  list<DayPoint>  $filtered
     * @return list<DayPoint>
     */
    private function allHours(ReportScope $scope, User $user, array $filtered, Metrics $metrics, ReportCache $cache): array
    {
        $f = $scope->filters;

        if ($f->clientIds === [] && $f->projectIds === [] && $f->bankIds === [] && $f->taskTypeIds === [] && $f->billable === null) {
            return $filtered;
        }

        $plain = $scope->withFilters(new ReportFilters($f->period, $f->from, $f->to, userIds: [$user->id]));

        return $cache->remember($plain, 'r1.person.days', fn (): array => $metrics->series($plain, Dimension::Day));
    }

    /**
     * Días con capacidad y sin ninguna hora, hasta ayer.
     *
     * @param  list<DayPoint>  $series
     * @return list<array{date: string, capacity_minutes: int, week: string}>
     */
    private function unloggedDays(array $series): array
    {
        $yesterday = LocalTime::today()->subDay()->toDateString();
        $days = [];

        foreach ($series as $day) {
            if ($day['bucket'] <= $yesterday && $day['capacity_minutes'] > 0 && $day['logged_minutes'] === 0) {
                $days[] = [
                    'date' => $day['bucket'],
                    'capacity_minutes' => $day['capacity_minutes'],
                    'week' => Week::containing($day['bucket'])->iso(),
                ];
            }
        }

        return $days;
    }

    /**
     * Detalle diario para exportar: fecha, día, capacidad, imputadas, facturables, ocupación y,
     * con datos económicos, el ingreso estimado.
     *
     * @param  list<DayPoint>  $days
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function daysTable(array $days, bool $financials): array
    {
        $headers = [
            __('reports.r1.columns.date'),
            __('reports.r1.columns.weekday'),
            __('reports.r1.columns.capacity'),
            __('reports.r1.columns.logged'),
            __('reports.r1.columns.billable'),
            __('reports.r1.columns.occupancy'),
        ];

        if ($financials) {
            $headers[] = __('reports.r1.columns.income');
        }

        $rows = [];
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day['bucket']);
            $line = [
                $date->format('d/m/Y'),
                __('reports.r1.weekdays.'.$date->dayOfWeekIso),
                TableExporter::hours($day['capacity_minutes']),
                TableExporter::hours($day['logged_minutes']),
                TableExporter::hours($day['billable_minutes']),
                self::percent(Metrics::ratio($day['logged_minutes'], $day['capacity_minutes'])),
            ];

            if ($financials) {
                $line[] = TableExporter::money($day['income']);
            }

            $rows[] = $line;
        }

        return [$headers, $rows];
    }
}
