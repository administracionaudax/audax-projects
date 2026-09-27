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

        $previous = $scope->withFilters($scope->filters->comparison());
        $days = self::elapsedDays($scope->filters);
        $partial = $days !== null && $days < $previous->filters->days();

        $summary = $partial
            ? $cache->remember($previous, $key.'.first.'.$days, fn (): array => $metrics->summaryFirstDays($previous, $days, $withCapacity, $everyAssignee))
            : $cache->remember($previous, $key.'.previous', fn (): array => $metrics->summary($previous, $withCapacity, $everyAssignee));

        $to = $partial ? $previous->filters->from->addDays($days - 1) : $previous->filters->to;

        return [
            'summary' => $summary,
            'range' => ['from' => $previous->filters->from->toDateString(), 'to' => $to->toDateString()],
            'partial' => $partial,
        ];
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
