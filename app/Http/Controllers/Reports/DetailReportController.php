<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informe de horas detallado /informes/detalle (SPEC §10.6): tabla dinámica por dos dimensiones
 * cualesquiera con subtotales (PivotReport), para cualquier interno con su alcance (D-044: cada uno
 * ve lo suyo). Sin datos económicos: solo horas.
 *
 * URL: los filtros globales (ReportFilters) más filas=, columnas= (Dimension) y medida=
 * imputadas|facturables|dentro|exceso. Los valores que no se entienden se ignoran. Con
 * ?formato=xlsx|csv exporta la tabla tal cual, con los subtotales.
 */
class DetailReportController extends Controller
{
    use BuildsReportScope;

    /**
     * Dimensiones que se pueden elegir, en el orden de los desplegables (sin «día»: demasiadas columnas).
     */
    public const array DIMENSIONS = [
        Dimension::Person, Dimension::Department, Dimension::Client, Dimension::Project, Dimension::HourBank,
        Dimension::TaskType, Dimension::Task, Dimension::Week, Dimension::Month,
    ];

    /**
     * Medida de la URL (en español) → medida de PivotReport.
     */
    public const array MEASURES = [
        'imputadas' => 'logged',
        'facturables' => 'billable',
        'dentro' => 'in_bank',
        'exceso' => 'overage',
    ];

    public const string DEFAULT_MEASURE = 'imputadas';

    /**
     * Filtros globales de la barra, en su orden (ReportFilterKey en resources/js/types/reports.ts).
     */
    public const array FILTERS = ['persona', 'departamento', 'cliente', 'proyecto', 'bolsa', 'tipo', 'facturable'];

    public function __invoke(Request $request, PivotReport $pivot, Metrics $metrics, ReportCache $cache, TableExporter $exporter): Response|StreamedResponse
    {
        Gate::authorize('viewDetailReport', TimeEntry::class);

        $scope = $this->reportScope($request);
        $dimensions = $this->dimensions($scope);
        [$rows, $columns, $measure] = $this->layout($request, $dimensions);

        $result = $cache->remember(
            $scope,
            "r3.detail.pivot.{$rows->value}.{$columns->value}.{$measure}",
            fn (): array => self::chronological($pivot->run($scope, $rows, $columns, self::MEASURES[$measure]), $rows),
        );

        if ($request->filled('formato')) {
            return $this->export($exporter, $scope, $result, $rows, $columns, $measure, (string) $request->query('formato'));
        }

        $layout = ['filas' => $rows->value, 'columnas' => $columns->value, 'medida' => $measure];
        $filters = $this->filterProps($scope);

        // Las elecciones de la tabla viajan con los filtros: al cambiar de periodo o de filtro se conservan.
        foreach (['query', 'previous', 'next'] as $key) {
            $filters[$key] = [...$filters[$key], ...$layout];
        }

        $comparison = $scope->filters->compare ? $scope->withFilters($scope->filters->comparison()) : null;

        return Inertia::render('reports/detail', [
            'filters' => $filters,
            'layout' => $layout,
            'dimensions' => array_map(fn (Dimension $dimension): string => $dimension->value, $dimensions),
            'filterKeys' => $this->filterKeys($scope),
            'measures' => array_keys(self::MEASURES),
            'pivot' => $result,
            'summary' => $this->summary($cache, $metrics, $scope),
            'comparison' => $comparison !== null ? $this->summary($cache, $metrics, $comparison) : null,
        ]);
    }

    /**
     * Dimensiones disponibles para quien mira: «persona» solo si su alcance tiene más de una persona
     * (admin, responsable de algún departamento o gestor de algún proyecto, D-021); un empleado solo
     * se ve a sí mismo.
     *
     * @return list<Dimension>
     */
    private function dimensions(ReportScope $scope): array
    {
        $viewer = $scope->viewer;
        $seesOthers = $viewer->isAdmin() || $viewer->managedDepartmentIds() !== [] || $viewer->managedProjectIds() !== [];

        return array_values(array_filter(self::DIMENSIONS, fn (Dimension $dimension): bool => $seesOthers || $dimension !== Dimension::Person));
    }

    /**
     * Filtros de la barra (ReportFilterBar): persona y departamento solo para quien tiene equipo
     * (admin o responsable de algún departamento), que son los que tienen opciones que elegir
     * (ReportOptionsController). Un gestor ve las horas de sus proyectos y los filtra por proyecto;
     * un empleado solo se ve a sí mismo.
     *
     * @return list<string>
     */
    private function filterKeys(ReportScope $scope): array
    {
        $viewer = $scope->viewer;
        $team = $viewer->isAdmin() || $viewer->managedDepartmentIds() !== [];

        return array_values(array_filter(self::FILTERS, fn (string $key): bool => $team || ! in_array($key, ['persona', 'departamento'], true)));
    }

    /**
     * Filas, columnas y medida de la URL. Por defecto, proyecto × semana con las horas imputadas; las
     * columnas nunca repiten la dimensión de las filas.
     *
     * @param  list<Dimension>  $dimensions
     * @return array{0: Dimension, 1: Dimension, 2: string}
     */
    private function layout(Request $request, array $dimensions): array
    {
        $pick = function (string $key) use ($request, $dimensions): ?Dimension {
            $value = $request->query($key);
            $dimension = is_string($value) ? Dimension::tryFrom($value) : null;

            return in_array($dimension, $dimensions, true) ? $dimension : null;
        };

        $rows = $pick('filas') ?? Dimension::Project;
        $columns = $pick('columnas');

        if ($columns === null || $columns === $rows) {
            $columns = $rows === Dimension::Week ? Dimension::Project : Dimension::Week;
        }

        $measure = $request->query('medida');
        $measure = is_string($measure) && array_key_exists($measure, self::MEASURES) ? $measure : self::DEFAULT_MEASURE;

        return [$rows, $columns, $measure];
    }

    /**
     * Con semanas o meses en las filas, van en orden de fecha (PivotReport las ordena por horas,
     * como al resto de dimensiones). En la página y en la exportación.
     *
     * @param  array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}  $result
     * @return array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}
     */
    private static function chronological(array $result, Dimension $rows): array
    {
        if ($rows->isTime()) {
            usort($result['rows'], fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        }

        return $result;
    }

    /**
     * KPIs de horas del informe (Metrics::summary, en caché). Sin datos económicos.
     *
     * @return array{logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, billability: float|null}
     */
    private function summary(ReportCache $cache, Metrics $metrics, ReportScope $scope): array
    {
        return $cache->remember($scope, 'r3.detail.summary', function () use ($metrics, $scope): array {
            $summary = $metrics->summary($scope);

            return [
                'logged_minutes' => $summary['logged_minutes'],
                'billable_minutes' => $summary['billable_minutes'],
                'in_bank_minutes' => $summary['in_bank_minutes'],
                'overage_minutes' => $summary['overage_minutes'],
                'billability' => $summary['billability'],
            ];
        });
    }

    /**
     * La tabla tal cual: una fila por grupo con sus celdas y su subtotal, y la fila de totales por
     * columna. Horas en decimal (1,5 = 1 h 30 min); las celdas sin horas, vacías.
     *
     * @param  array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}  $result
     */
    private function export(TableExporter $exporter, ReportScope $scope, array $result, Dimension $rows, Dimension $columns, string $measure, string $format): StreamedResponse
    {
        $headers = [
            self::text('reports.r3.detail.corner', ['rows' => $rows->label(), 'columns' => $columns->label()]),
            ...array_map(fn (array $column): string => self::headerLabel($columns, $column), $result['columns']),
            self::text('reports.r3.detail.total'),
        ];

        $lines = [];
        foreach ($result['rows'] as $row) {
            $rowKey = $row['key'] ?? '';
            $line = [self::headerLabel($rows, $row)];
            foreach ($result['columns'] as $column) {
                $minutes = $result['cells'][$rowKey][$column['key'] ?? ''] ?? null;
                $line[] = $minutes === null ? null : TableExporter::hours($minutes);
            }
            $line[] = TableExporter::hours($result['row_totals'][$rowKey] ?? 0);
            $lines[] = $line;
        }

        $totals = [self::text('reports.r3.detail.total')];
        foreach ($result['columns'] as $column) {
            $totals[] = TableExporter::hours($result['column_totals'][$column['key'] ?? ''] ?? 0);
        }
        $totals[] = TableExporter::hours($result['total']);
        $lines[] = $totals;

        if ($result['truncated']) {
            $lines[] = [self::text('reports.r3.detail.truncated', ['rows' => PivotReport::MAX_ROWS, 'columns' => PivotReport::MAX_COLUMNS])];
        }

        $basename = self::text('reports.r3.detail.filename', [
            'measure' => self::text("reports.r3.measures.{$measure}"),
            'rows' => Str::lower($rows->label()),
            'columns' => Str::lower($columns->label()),
            'from' => $scope->filters->from->toDateString(),
            'to' => $scope->filters->to->toDateString(),
        ]);

        return $exporter->download($basename, $headers, $lines, $format);
    }

    /**
     * Texto de una cabecera de fila o columna: las semanas por su lunes («Sem. 21/09/2026») y los
     * meses por su nombre («Septiembre 2026»); el resto, su nombre.
     *
     * @param  array{key: string|null, name: string}  $header
     */
    private static function headerLabel(Dimension $dimension, array $header): string
    {
        if ($header['key'] === null || ! $dimension->isTime()) {
            return $header['name'];
        }

        $date = CarbonImmutable::parse($header['key']);

        return match ($dimension) {
            Dimension::Week => self::text('reports.r3.detail.week', ['date' => $date->format('d/m/Y')]),
            Dimension::Month => Str::ucfirst($date->settings(['locale' => 'es'])->isoFormat('MMMM YYYY')),
            default => $date->format('d/m/Y'),
        };
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
