<?php

namespace App\Domain\Forecast;

use App\Domain\Time\CapacityPlan;
use Carbon\CarbonImmutable;

/**
 * Periodo de la previsión (docs/PLAN-CARGAS.md §5.3, D-285): de 1 a 12 meses, por semanas (de lunes
 * a domingo, como toda la app) o por meses naturales. El inicio se lleva al lunes o al día 1 y el
 * fin al domingo o al último día del mes, para que todas las columnas sean completas.
 */
final readonly class ForecastPeriod
{
    public const string WEEK = 'week';

    public const string MONTH = 'month';

    public const int MIN_MONTHS = 1;

    public const int MAX_MONTHS = 12;

    public const int DEFAULT_MONTHS = 3;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $granularity,
    ) {}

    /**
     * Desde $from (o hoy), $months meses, por semanas o por meses.
     */
    public static function make(CarbonImmutable $from, int $months = self::DEFAULT_MONTHS, string $granularity = self::MONTH): self
    {
        $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));
        $granularity = $granularity === self::WEEK ? self::WEEK : self::MONTH;
        $from = $from->startOfDay();

        if ($granularity === self::WEEK) {
            $start = $from->startOfWeek(CarbonImmutable::MONDAY);
            $end = $from->addMonthsNoOverflow($months)->subDay()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        } else {
            $start = $from->startOfMonth();
            $end = $start->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
        }

        return new self($start, $end, $granularity);
    }

    /**
     * Las columnas del periodo: clave («2026-11» o «2026-W45») y sus fechas.
     *
     * @return list<array{key: string, from: string, to: string}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $day = CapacityPlan::day($this->from->toDateString());
        $last = CapacityPlan::day($this->to->toDateString());

        while ($day <= $last) {
            $end = $this->granularity === self::WEEK ? $day + 7 - CapacityPlan::weekday($day) : AllocationPlanner::monthEnd($day);
            $end = min($end, $last);
            $time = $day * 86400;
            $buckets[] = [
                'key' => $this->granularity === self::WEEK ? gmdate('o-\WW', $time) : gmdate('Y-m', $time),
                'from' => CapacityPlan::date($day),
                'to' => CapacityPlan::date($end),
            ];
            $day = $end + 1;
        }

        return $buckets;
    }

    /**
     * Columna de cada día entre $from y el fin (días): día → índice de la columna.
     *
     * @return array<int, int>
     */
    public function bucketOfDays(int $from): array
    {
        $map = [];

        foreach ($this->buckets() as $index => $bucket) {
            $last = CapacityPlan::day($bucket['to']);

            for ($day = max($from, CapacityPlan::day($bucket['from'])); $day <= $last; $day++) {
                $map[$day] = $index;
            }
        }

        return $map;
    }
}
