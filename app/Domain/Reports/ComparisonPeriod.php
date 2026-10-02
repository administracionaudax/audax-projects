<?php

namespace App\Domain\Reports;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Comparación con el periodo anterior (comparar=1) común a todos los dashboards (D-079):
 * - periodo cerrado: el periodo anterior entero,
 * - periodo en curso: «al mismo punto», es decir, los mismos días transcurridos del periodo
 *   anterior (Metrics::summaryFirstDays). Comparar el mes a medias con el anterior entero daría
 *   a mitad de mes −50 % en horas e ingreso aunque se fuera al mismo ritmo.
 */
final class ComparisonPeriod
{
    /**
     * Días transcurridos de un periodo en curso, hoy incluido (hoy ya tiene horas), o null si el
     * periodo ya ha acabado o aún no ha empezado.
     */
    public static function elapsedDays(ReportFilters $filters): ?int
    {
        $today = LocalTime::todayString();

        if ($today < $filters->from->toDateString() || $today > $filters->to->toDateString()) {
            return null;
        }

        // Fechas de calendario en UTC: sin horas ni cambios de hora de por medio.
        return (int) CarbonImmutable::parse($filters->from->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC')) + 1;
    }

    /**
     * Resumen del periodo de comparación (null sin comparar=1), el tramo comparado (para la barra
     * de filtros) y si es un tramo parcial. $key identifica el dashboard en la caché.
     *
     * @return array{summary: array<string, mixed>|null, range: array{from: string, to: string}|null, partial: bool}
     */
    public static function summary(ReportScope $scope, Metrics $metrics, ReportCache $cache, string $key, bool $withCapacity = true, bool $everyAssignee = false): array
    {
        if (! $scope->filters->compare) {
            return ['summary' => null, 'range' => null, 'partial' => false];
        }

        ['previous' => $previous, 'days' => $days, 'to' => $to] = self::previous($scope);

        $summary = $days !== null
            ? $cache->remember($previous, $key.'.first.'.$days, fn (): array => $metrics->summaryFirstDays($previous, $days, $withCapacity, $everyAssignee))
            : $cache->remember($previous, $key.'.previous', fn (): array => $metrics->summary($previous, $withCapacity, $everyAssignee));

        return [
            'summary' => $summary,
            'range' => ['from' => $previous->filters->from->toDateString(), 'to' => $to->toDateString()],
            'partial' => $days !== null,
        ];
    }

    /**
     * Alcance del tramo comparado, para los informes que solo miden horas (el detallado, con
     * Metrics::hours): el periodo anterior entero o, en un periodo en curso, sus mismos días
     * transcurridos. Sin capacidad de por medio, el tramo es un alcance más con esas fechas (con
     * capacidad, summary() lo mide contra la del periodo anterior completo). Null sin comparar=1.
     *
     * @return array{scope: ReportScope, range: array{from: string, to: string}, partial: bool}|null
     */
    public static function hoursScope(ReportScope $scope): ?array
    {
        if (! $scope->filters->compare) {
            return null;
        }

        ['previous' => $previous, 'days' => $days, 'to' => $to] = self::previous($scope);

        return [
            'scope' => $days === null ? $previous : $previous->withFilters($previous->filters->withDates($previous->filters->from, $to)),
            'range' => ['from' => $previous->filters->from->toDateString(), 'to' => $to->toDateString()],
            'partial' => $days !== null,
        ];
    }

    /**
     * El periodo anterior con los mismos filtros, los días del tramo si es parcial (null: entero) y
     * el último día comparado.
     *
     * @return array{previous: ReportScope, days: int|null, to: CarbonImmutable}
     */
    private static function previous(ReportScope $scope): array
    {
        $previous = $scope->withFilters($scope->filters->comparison());
        $days = self::elapsedDays($scope->filters);

        if ($days === null || $days >= $previous->filters->days()) {
            return ['previous' => $previous, 'days' => null, 'to' => $previous->filters->to];
        }

        return ['previous' => $previous, 'days' => $days, 'to' => $previous->filters->from->addDays($days - 1)];
    }

    /**
     * Props de la barra de filtros con el tramo de comparación real.
     *
     * @param  array<string, mixed>  $props
     * @param  array{from: string, to: string}|null  $range
     * @return array<string, mixed>
     */
    public static function withRange(array $props, ?array $range): array
    {
        if ($range !== null) {
            $props['comparison'] = $range;
        }

        return $props;
    }
}
