<?php

namespace App\Http\Controllers\Reports\R1;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\Money;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Piezas comunes de los dashboards de R1 (dirección, departamento y persona). Todas las cifras
 * salen del contrato (ReportScope + Metrics) y pasan por ReportCache (D-046).
 *
 * @phpstan-type BreakdownRow array{key: string|null, name: string, color: string|null, logged_minutes: int,
 *     billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null}
 * @phpstan-type MarginRow array{key: string|null, name: string, color: string|null, logged_minutes: int,
 *     billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string|null, cost: string|null, margin: string|null}
 * @phpstan-type OthersRow array{count: int, logged_minutes: int, billable_minutes: int, in_bank_minutes: int,
 *     overage_minutes: int, income: string|null, cost: string|null, margin: string|null}
 */
trait BuildsDashboards
{
    /**
     * Hasta un trimestre (92 días), la evolución va por semanas; si el periodo es mayor, por meses.
     */
    public const int WEEKLY_MAX_DAYS = 92;

    /**
     * Resumen del periodo (Metrics::summary, SPEC §10) con la capacidad transcurrida hasta ayer
     * como dato informativo (capacity_to_date_minutes; la ocupación no se mide contra ella) y, con
     * comparar=1, el del periodo anterior con los mismos filtros.
     *
     * Si el periodo está en curso (hoy cae dentro), la comparación es «al mismo punto»: los mismos
     * días transcurridos del periodo anterior frente a su capacidad completa
     * (Metrics::summaryFirstDays). Comparar el mes a medias con el anterior entero daría a mitad de
     * mes −50 % en horas, ingreso y ocupación aunque se fuera al mismo ritmo. `comparison_range` es
     * el tramo comparado (para la barra de filtros) y `comparison_partial` dice si es un tramo.
     *
     * @return array{summary: array<string, mixed>, comparison: array<string, mixed>|null,
     *     comparison_range: array{from: string, to: string}|null, comparison_partial: bool}
     */
    protected function summaries(ReportScope $scope, Metrics $metrics, ReportCache $cache): array
    {
        $summary = $cache->remember($scope, self::daily('r1.summary'), fn (): array => $metrics->summary($scope)
            + ['capacity_to_date_minutes' => $metrics->elapsedCapacity($scope)]);

        if (! $scope->filters->compare) {
            return ['summary' => $summary, 'comparison' => null, 'comparison_range' => null, 'comparison_partial' => false];
        }

        $previous = $scope->withFilters($scope->filters->comparison());
        $days = self::elapsedDays($scope->filters);
        $partial = $days !== null && $days < $previous->filters->days();
        $comparison = $partial
            ? $cache->remember($previous, 'r1.summary.first.'.$days, fn (): array => $metrics->summaryFirstDays($previous, $days))
            : $cache->remember($previous, 'r1.summary.previous', fn (): array => $metrics->summary($previous));
        $to = $partial ? $previous->filters->from->addDays($days - 1) : $previous->filters->to;

        return [
            'summary' => $summary,
            'comparison' => $comparison,
            'comparison_range' => ['from' => $previous->filters->from->toDateString(), 'to' => $to->toDateString()],
            'comparison_partial' => $partial,
        ];
    }

    /**
     * Días transcurridos de un periodo en curso, hoy incluido (hoy ya tiene horas), o null si el
     * periodo ya ha acabado o aún no ha empezado.
     */
    protected static function elapsedDays(ReportFilters $filters): ?int
    {
        $today = LocalTime::todayString();

        if ($today < $filters->from->toDateString() || $today > $filters->to->toDateString()) {
            return null;
        }

        // Fechas de calendario en UTC: sin horas ni cambios de hora de por medio.
        return (int) CarbonImmutable::parse($filters->from->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC')) + 1;
    }

    /**
     * Props de la barra de filtros con el tramo de comparación real (en un periodo en curso, los
     * mismos días del periodo anterior: summaries()).
     *
     * @param  array<string, mixed>  $props
     * @param  array{from: string, to: string}|null  $range
     * @return array<string, mixed>
     */
    protected static function withComparisonRange(array $props, ?array $range): array
    {
        if ($range !== null) {
            $props['comparison'] = $range;
        }

        return $props;
    }

    /**
     * Nombre de caché de un bloque que depende del día de hoy (capacidad transcurrida, tareas
     * vencidas…): la clave de ReportCache lleva el periodo pero no la fecha, y sin ella un bloque
     * calculado antes de medianoche se serviría hasta 10 minutos después.
     */
    protected static function daily(string $name): string
    {
        return $name.'@'.LocalTime::todayString();
    }

    /**
     * Añade a cada fila el margen (ingreso − coste, bcmath) si hay datos económicos (null si no).
     *
     * @param  list<BreakdownRow>  $rows
     * @return list<MarginRow>
     */
    protected function withMargin(array $rows): array
    {
        return array_map(fn (array $row): array => $row + [
            'margin' => $row['income'] === null ? null : Money::round(Money::sub($row['income'], $row['cost'] ?? '0')),
        ], $rows);
    }

    /**
     * Las $limit primeras filas (ya ordenadas por horas) y el resto sumado («Otros»), o null.
     *
     * @param  list<MarginRow>  $rows
     * @return array{rows: list<MarginRow>, others: OthersRow|null}
     */
    protected function top(array $rows, int $limit): array
    {
        $rest = array_slice($rows, $limit);

        if ($rest === []) {
            return ['rows' => $rows, 'others' => null];
        }

        $money = $rest[0]['income'] !== null;
        $others = [
            'count' => count($rest),
            'logged_minutes' => array_sum(array_column($rest, 'logged_minutes')),
            'billable_minutes' => array_sum(array_column($rest, 'billable_minutes')),
            'in_bank_minutes' => array_sum(array_column($rest, 'in_bank_minutes')),
            'overage_minutes' => array_sum(array_column($rest, 'overage_minutes')),
            'income' => $money ? Money::round(Money::add(...array_map(fn (array $row): string => (string) $row['income'], $rest))) : null,
            'cost' => $money ? Money::round(Money::add(...array_map(fn (array $row): string => (string) $row['cost'], $rest))) : null,
            'margin' => $money ? Money::round(Money::add(...array_map(fn (array $row): string => (string) $row['margin'], $rest))) : null,
        ];

        return ['rows' => array_slice($rows, 0, $limit), 'others' => $others];
    }

    /**
     * Props de la barra de filtros sin los filtros fijos del dashboard (p. ej. la persona en su
     * propio informe): no ensucian la URL al navegar entre periodos.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function filterPropsWithout(ReportScope $scope, array $keys): array
    {
        $props = $this->filterProps($scope);

        foreach (['query', 'previous', 'next'] as $part) {
            if (is_array($props[$part])) {
                foreach ($keys as $key) {
                    unset($props[$part][$key]);
                }
            }
        }

        return $props;
    }

    protected function seriesBucket(ReportFilters $filters): Dimension
    {
        return $filters->days() <= self::WEEKLY_MAX_DAYS ? Dimension::Week : Dimension::Month;
    }

    /**
     * ?formato=xlsx|csv pide la exportación (D-045); cualquier otro valor muestra la página.
     */
    protected function exportFormat(Request $request): ?string
    {
        $format = $request->query('formato');

        return is_string($format) && in_array($format, TableExporter::FORMATS, true) ? $format : null;
    }

    /**
     * Tabla de exportación de un desglose: nombre, horas, facturables, % del total y
     * facturabilidad; con datos económicos, además ingreso, coste y margen.
     *
     * @param  list<MarginRow>  $rows
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    protected function breakdownTable(string $firstColumn, array $rows, int $totalMinutes, bool $financials): array
    {
        $headers = [
            $firstColumn,
            __('reports.r1.columns.logged'),
            __('reports.r1.columns.billable'),
            __('reports.r1.columns.share'),
            __('reports.r1.columns.billability'),
        ];

        if ($financials) {
            array_push($headers, __('reports.r1.columns.income'), __('reports.r1.columns.cost'), __('reports.r1.columns.margin'));
        }

        $lines = [];
        foreach ($rows as $row) {
            $line = [
                $row['name'],
                TableExporter::hours($row['logged_minutes']),
                TableExporter::hours($row['billable_minutes']),
                self::percent(Metrics::ratio($row['logged_minutes'], $totalMinutes)),
                self::percent(Metrics::ratio($row['billable_minutes'], $row['logged_minutes'])),
            ];

            if ($financials) {
                array_push($line, TableExporter::money($row['income']), TableExporter::money($row['cost']), TableExporter::money($row['margin']));
            }

            $lines[] = $line;
        }

        return [$headers, $lines];
    }

    /**
     * Ratio (0,3944) → porcentaje con un decimal (39,4) para las celdas; null sin base.
     */
    protected static function percent(?float $ratio): ?float
    {
        return $ratio === null ? null : round($ratio * 100, 1);
    }
}
