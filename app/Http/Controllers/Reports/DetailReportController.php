<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ComparisonPeriod;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Task;
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
 * ?formato=xlsx|csv exporta la tabla tal cual, con los subtotales (otro valor muestra la página).
 * Con comparar=1, los KPIs de horas del periodo anterior; en un periodo en curso, de sus mismos
 * días transcurridos (ComparisonPeriod, D-079).
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
            // Sin las celdas a 0: con facturables, dentro o exceso, solo los grupos que tienen esas horas
            // (y, si no hay ninguno, el estado vacío en vez de una tabla de ceros).
            fn (): array => self::withTaskProjects(
                self::chronological($pivot->run($scope, $rows, $columns, self::MEASURES[$measure], withoutEmpty: true), $rows),
                $rows,
                $columns,
            ),
        );

        $format = $this->exportFormat($request);
        if ($format !== null) {
            return $this->export($exporter, $scope, $result, $rows, $columns, $measure, $format);
        }

        $layout = ['filas' => $rows->value, 'columnas' => $columns->value, 'medida' => $measure];
        $filters = $this->filterProps($scope);

        // Las elecciones de la tabla viajan con los filtros: al cambiar de periodo o de filtro se conservan.
        foreach (['query', 'previous', 'next'] as $key) {
            $filters[$key] = [...$filters[$key], ...$layout];
        }

        // Comparar: en un periodo en curso, con los mismos días del anterior (D-079), como el resto de dashboards.
        $comparison = ComparisonPeriod::hoursScope($scope);

        return Inertia::render('reports/detail', [
            'filters' => ComparisonPeriod::withRange($filters, $comparison['range'] ?? null),
            'layout' => $layout,
            'dimensions' => array_map(fn (Dimension $dimension): string => $dimension->value, $dimensions),
            'filterKeys' => $this->filterKeys($scope),
            'measures' => array_keys(self::MEASURES),
            'pivot' => $result,
            'summary' => $this->summary($cache, $metrics, $scope),
            'comparison' => $comparison !== null ? $this->summary($cache, $metrics, $comparison['scope']) : null,
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
     * Con tareas en filas o columnas, cada una con el código de su proyecto delante («TM · Maquetación»):
     * muchas tareas se llaman igual en proyectos distintos («Entrega al cliente»). Una consulta.
     *
     * @param  array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}  $result
     * @return array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}
     */
    private static function withTaskProjects(array $result, Dimension $rows, Dimension $columns): array
    {
        $sides = array_keys(array_filter(['rows' => $rows, 'columns' => $columns], fn (Dimension $dimension): bool => $dimension === Dimension::Task));

        $ids = [];
        foreach ($sides as $side) {
            foreach ($result[$side] as $header) {
                if ($header['key'] !== null) {
                    $ids[] = (int) $header['key'];
                }
            }
        }

        if ($ids === []) {
            return $result;
        }

        // Tareas y proyectos también borrados: sus horas siguen en los informes.
        $codes = Task::query()->withTrashed()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereIn('tasks.id', array_values(array_unique($ids)))
            ->toBase()
            ->pluck('projects.code', 'tasks.id');

        foreach ($sides as $side) {
            $result[$side] = array_map(function (array $header) use ($codes): array {
                $code = $header['key'] !== null ? $codes->get((int) $header['key']) : null;

                return is_string($code) && $code !== '' ? ['key' => $header['key'], 'name' => $code.' · '.$header['name']] : $header;
            }, $result[$side]);
        }

        return $result;
    }

    /**
     * KPIs de horas del informe (Metrics::hours, en caché): sin capacidad, estimación ni datos
     * económicos, que el detallado no muestra.
     *
     * @return array{logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, billability: float|null}
     */
    private function summary(ReportCache $cache, Metrics $metrics, ReportScope $scope): array
    {
        return $cache->remember($scope, 'r3.detail.hours', fn (): array => $metrics->hours($scope));
    }

    /**
     * La tabla tal cual: una fila por grupo con sus celdas y su subtotal, y la fila de totales por
     * columna. Horas en decimal (1,5 = 1 h 30 min); las celdas sin horas, vacías. Los subtotales y
     * los totales van además en minutos enteros (una columna y una fila «Total (minutos)», D-081):
     * las horas redondeadas a 2 decimales no siempre suman su total, los minutos sí.
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
            self::text('reports.r3.detail.total_minutes'),
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
            $line[] = $result['row_totals'][$rowKey] ?? 0;
            $lines[] = $line;
        }

        $totals = [self::text('reports.r3.detail.total')];
        $totalMinutes = [self::text('reports.r3.detail.total_minutes')];
        foreach ($result['columns'] as $column) {
            $totals[] = TableExporter::hours($result['column_totals'][$column['key'] ?? ''] ?? 0);
            $totalMinutes[] = $result['column_totals'][$column['key'] ?? ''] ?? 0;
        }
        $totals[] = TableExporter::hours($result['total']);
        $totals[] = $result['total'];
        $totalMinutes[] = null;
        $totalMinutes[] = $result['total'];
        $lines[] = $totals;
        // D-081: los totales por columna también en minutos (enteros), que suman exacto el total.
        $lines[] = $totalMinutes;

        if ($result['truncated']) {
            $lines[] = [self::truncationNotice($result, $columns)];
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
     * Aviso de tabla recortada que dice lo que se ve: las filas con más horas y, en columnas, las que
     * tienen más horas o, si son semanas, meses o días, las primeras del periodo (PivotReport las
     * ordena por fecha antes de recortar).
     *
     * @param  array{row_totals: array<string, int>, column_totals: array<string, int>}  $result
     */
    private static function truncationNotice(array $result, Dimension $columns): string
    {
        $parts = [];

        if (count($result['row_totals']) > PivotReport::MAX_ROWS) {
            $parts[] = self::text('reports.r3.detail.truncated_rows', ['count' => PivotReport::MAX_ROWS]);
        }

        if (count($result['column_totals']) > PivotReport::MAX_COLUMNS) {
            $parts[] = self::text(match ($columns) {
                Dimension::Week => 'reports.r3.detail.truncated_weeks',
                Dimension::Month => 'reports.r3.detail.truncated_months',
                Dimension::Day => 'reports.r3.detail.truncated_days',
                default => 'reports.r3.detail.truncated_columns',
            }, ['count' => PivotReport::MAX_COLUMNS]);
        }

        $shown = count($parts) === 2
            ? self::text('reports.r3.detail.truncated_and', ['first' => $parts[0], 'second' => $parts[1]])
            : implode('', $parts);

        return self::text('reports.r3.detail.truncated', ['shown' => $shown]);
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
