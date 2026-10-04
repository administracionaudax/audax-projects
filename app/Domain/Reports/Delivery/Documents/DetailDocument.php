<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Informe detallado (/informes/detalle, SPEC §10.6): la tabla dinámica por dos dimensiones con
 * subtotales (PivotReport) con el alcance de quien mira (D-044), su exportación (la tabla tal cual)
 * y su PDF (en apaisado; si tiene muchas columnas, en varias tablas). Sin datos económicos.
 *
 * @phpstan-type Pivot array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
 *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
 *     total: int, truncated: bool}
 */
final class DetailDocument extends BaseDocument
{
    use BuildsReportScope, PdfPieces;

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

    /** Columnas de datos por tabla en el PDF (A4 apaisado); con más, la tabla se parte. */
    public const int PDF_COLUMNS = 10;

    private const array KPIS = ['logged', 'billable', 'billability', 'in_bank', 'overage'];

    public function __construct(
        private readonly PivotReport $pivot,
        private readonly Metrics $metrics,
        private readonly ReportCache $cache,
    ) {}

    /**
     * Dimensiones disponibles para quien mira: «persona» solo si su alcance tiene más de una persona
     * (admin, responsable de algún departamento o gestor de algún proyecto, D-021); un empleado solo
     * se ve a sí mismo.
     *
     * @return list<Dimension>
     */
    public function dimensions(User $viewer): array
    {
        $seesOthers = $viewer->isAdmin() || $viewer->managedDepartmentIds() !== [] || $viewer->managedProjectIds() !== [];

        return array_values(array_filter(self::DIMENSIONS, fn (Dimension $dimension): bool => $seesOthers || $dimension !== Dimension::Person));
    }

    /**
     * Filas, columnas y medida de la URL. Por defecto, proyecto × semana con las horas imputadas; las
     * columnas nunca repiten la dimensión de las filas.
     *
     * @param  array<string, mixed>  $query
     * @param  list<Dimension>  $dimensions
     * @return array{0: Dimension, 1: Dimension, 2: string}
     */
    public function layout(array $query, array $dimensions): array
    {
        $pick = function (string $key) use ($query, $dimensions): ?Dimension {
            $value = $query[$key] ?? null;
            $dimension = is_string($value) ? Dimension::tryFrom($value) : null;

            return in_array($dimension, $dimensions, true) ? $dimension : null;
        };

        $rows = $pick('filas') ?? Dimension::Project;
        $columns = $pick('columnas');

        if ($columns === null || $columns === $rows) {
            $columns = $rows === Dimension::Week ? Dimension::Project : Dimension::Week;
        }

        $measure = $query['medida'] ?? null;
        $measure = is_string($measure) && array_key_exists($measure, self::MEASURES) ? $measure : self::DEFAULT_MEASURE;

        return [$rows, $columns, $measure];
    }

    /**
     * La tabla dinámica (en caché): sin las celdas a 0 (con facturables, dentro o exceso, solo los
     * grupos que tienen esas horas), en orden de fecha si las filas son de tiempo y con el código del
     * proyecto delante de cada tarea.
     *
     * @return Pivot
     */
    public function result(ReportScope $scope, Dimension $rows, Dimension $columns, string $measure): array
    {
        return $this->cache->remember(
            $scope,
            "r3.detail.pivot.{$rows->value}.{$columns->value}.{$measure}",
            fn (): array => self::withTaskProjects(
                self::chronological($this->pivot->run($scope, $rows, $columns, self::MEASURES[$measure], withoutEmpty: true), $rows),
                $rows,
                $columns,
            ),
        );
    }

    /**
     * KPIs de horas del informe (Metrics::hours, en caché): sin capacidad, estimación ni datos
     * económicos, que el detallado no muestra.
     *
     * @return array{logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, billability: float|null}
     */
    public function summary(ReportScope $scope): array
    {
        return $this->cache->remember($scope, 'r3.detail.hours', fn (): array => $this->metrics->hours($scope));
    }

    public function title(ReportRequest $request, User $as): string
    {
        [$scope, $rows, $columns, $measure] = $this->authorized($request, $as);

        return $this->titleFor($scope, $rows, $columns, $measure);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$scope, $rows, $columns, $measure] = $this->authorized($request, $as);

        return $this->exportTable($scope, $this->result($scope, $rows, $columns, $measure), $rows, $columns, $measure, $this->titleFor($scope, $rows, $columns, $measure));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$scope, $rows, $columns, $measure] = $this->authorized($request, $as);
        $result = $this->result($scope, $rows, $columns, $measure);

        $facts = self::filterFacts($scope->filters);
        array_splice($facts, 1, 0, [
            [self::t('report_pdf.detail.layout'), self::t('report_pdf.detail.layout_value', [
                'rows' => $rows->label(), 'columns' => $columns->label(), 'measure' => self::t("reports.r3.measures.{$measure}"),
            ])],
        ]);

        return new ReportPdf(
            view: 'reports.pdf.detail',
            title: $this->titleFor($scope, $rows, $columns, $measure),
            filename: self::filename(self::t('report_pdf.files.detail'), Str::lower($rows->label()), Str::lower($columns->label()), PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.detail'),
                    self::t('report_pdf.detail.title', ['measure' => self::t("reports.r3.measures.{$measure}"), 'rows' => Str::lower($rows->label()), 'columns' => Str::lower($columns->label())]),
                    PdfFormat::period($scope->filters), $facts, $as),
                'kpis' => self::kpis($this->summary($scope), self::KPIS, false),
                'tables' => $this->pivotPdf($result, $rows, $columns),
                'truncated' => $result['truncated'] ? self::truncationNotice($result, $columns) : null,
                'definitions' => self::definitions(self::KPIS, false),
            ],
            landscape: true,
        );
    }

    /**
     * @return array{0: ReportScope, 1: Dimension, 2: Dimension, 3: string}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        self::authorizeFor($as, 'viewDetailReport', TimeEntry::class);

        return [$this->scopeFor($as, $request->query), ...$this->layout($request->query, $this->dimensions($as))];
    }

    private function titleFor(ReportScope $scope, Dimension $rows, Dimension $columns, string $measure): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.detail'),
            self::t('report_pdf.detail.title', ['measure' => self::t("reports.r3.measures.{$measure}"), 'rows' => Str::lower($rows->label()), 'columns' => Str::lower($columns->label())]),
            PdfFormat::period($scope->filters));
    }

    /**
     * La tabla en trozos de PDF_COLUMNS columnas (cada uno con la columna de filas); el total por
     * fila y la fila de totales, en el último.
     *
     * @param  Pivot  $result
     * @return list<array<string, mixed>>
     */
    private function pivotPdf(array $result, Dimension $rows, Dimension $columns): array
    {
        $chunks = array_chunk($result['columns'], self::PDF_COLUMNS) ?: [[]];
        $tables = [];

        foreach ($chunks as $index => $chunk) {
            $last = $index === count($chunks) - 1;
            $header = [[self::t('reports.r3.detail.corner', ['rows' => $rows->label(), 'columns' => $columns->label()])]];
            foreach ($chunk as $column) {
                $header[] = [self::headerLabel($columns, $column), true];
            }
            if ($last) {
                $header[] = [self::t('report_pdf.total'), true];
            }

            $lines = [];
            foreach ($result['rows'] as $row) {
                $rowKey = $row['key'] ?? '';
                $line = [self::headerLabel($rows, $row)];
                foreach ($chunk as $column) {
                    $minutes = $result['cells'][$rowKey][$column['key'] ?? ''] ?? null;
                    $line[] = $minutes === null ? '' : PdfFormat::minutes($minutes);
                }
                if ($last) {
                    $line[] = PdfFormat::minutes($result['row_totals'][$rowKey] ?? 0);
                }
                $lines[] = $line;
            }

            $sum = [self::t('report_pdf.total')];
            foreach ($chunk as $column) {
                $sum[] = PdfFormat::minutes($result['column_totals'][$column['key'] ?? ''] ?? 0);
            }
            if ($last) {
                $sum[] = PdfFormat::minutes($result['total']);
            }

            $tables[] = PdfTable::make($header, $lines, $lines === [] ? null : $sum, compact: true, empty: self::t('report_pdf.no_hours'));
        }

        return $tables;
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
     * La tabla tal cual: una fila por grupo con sus celdas y su subtotal, y la fila de totales por
     * columna. Horas en decimal (1,5 = 1 h 30 min); las celdas sin horas, vacías. Los subtotales y
     * los totales van además en minutos enteros (una columna y una fila «Total (minutos)», D-081):
     * las horas redondeadas a 2 decimales no siempre suman su total, los minutos sí.
     *
     * @param  array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}  $result
     */
    private function exportTable(ReportScope $scope, array $result, Dimension $rows, Dimension $columns, string $measure, string $title): ExportTable
    {
        $headers = [
            self::t('reports.r3.detail.corner', ['rows' => $rows->label(), 'columns' => $columns->label()]),
            ...array_map(fn (array $column): string => self::headerLabel($columns, $column), $result['columns']),
            self::t('reports.r3.detail.total'),
            self::t('reports.r3.detail.total_minutes'),
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

        $totals = [self::t('reports.r3.detail.total')];
        $totalMinutes = [self::t('reports.r3.detail.total_minutes')];
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

        $basename = self::t('reports.r3.detail.filename', [
            'measure' => self::t("reports.r3.measures.{$measure}"),
            'rows' => Str::lower($rows->label()),
            'columns' => Str::lower($columns->label()),
            'from' => $scope->filters->from->toDateString(),
            'to' => $scope->filters->to->toDateString(),
        ]);

        return new ExportTable($basename, $headers, $lines, $title);
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
            $parts[] = self::t('reports.r3.detail.truncated_rows', ['count' => PivotReport::MAX_ROWS]);
        }

        if (count($result['column_totals']) > PivotReport::MAX_COLUMNS) {
            $parts[] = self::t(match ($columns) {
                Dimension::Week => 'reports.r3.detail.truncated_weeks',
                Dimension::Month => 'reports.r3.detail.truncated_months',
                Dimension::Day => 'reports.r3.detail.truncated_days',
                default => 'reports.r3.detail.truncated_columns',
            }, ['count' => PivotReport::MAX_COLUMNS]);
        }

        $shown = count($parts) === 2
            ? self::t('reports.r3.detail.truncated_and', ['first' => $parts[0], 'second' => $parts[1]])
            : implode('', $parts);

        return self::t('reports.r3.detail.truncated', ['shown' => $shown]);
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
            Dimension::Week => self::t('reports.r3.detail.week', ['date' => $date->format('d/m/Y')]),
            Dimension::Month => Str::ucfirst($date->settings(['locale' => 'es'])->isoFormat('MMMM YYYY')),
            default => $date->format('d/m/Y'),
        };
    }
}
