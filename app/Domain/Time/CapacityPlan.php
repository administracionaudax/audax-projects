<?php

namespace App\Domain\Time;

/**
 * Capacidad de UNA persona en un rango de fechas como tramos (PERF-05): cada tramo es un intervalo
 * de días con la misma semana de minutos (lunes primero), la de una versión de su horario o la
 * jornada por defecto, y los tramos cubren el rango sin huecos. Las sumas se hacen con aritmética
 * (semanas completas × la semana, más los días sueltos), sin recorrer los días uno a uno.
 *
 * $overrides son los días cuya capacidad no es la de su semana (festivos y ausencias, que la Fase 3
 * descuenta en Capacity): día → minutos. Las sumas restan la diferencia de esos días.
 *
 * Los días se guardan como número de día desde el 01/01/1970 (UTC, sin cambios de hora).
 *
 * @phpstan-type Segment array{from: int, to: int, week: list<int>}
 */
final readonly class CapacityPlan
{
    /**
     * @param  list<Segment>  $segments  Ordenados y contiguos.
     * @param  array<int, int>  $overrides  Día → minutos de capacidad de ese día.
     */
    public function __construct(
        public array $segments,
        public array $overrides = [],
    ) {}

    /**
     * Número de día de una fecha AAAA-MM-DD.
     */
    public static function day(string $date): int
    {
        return intdiv((int) gmmktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 86400);
    }

    /**
     * Fecha AAAA-MM-DD de un número de día.
     */
    public static function date(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }

    /**
     * Día de la semana ISO (1 = lunes … 7 = domingo) de un número de día (el 01/01/1970 fue jueves).
     */
    public static function weekday(int $day): int
    {
        return (($day % 7) + 7 + 3) % 7 + 1;
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /**
     * Minutos de capacidad entre $from y $to (AAAA-MM-DD, ambos incluidos; null: el principio o el
     * final del plan), con aritmética por tramos.
     */
    public function total(?string $from = null, ?string $to = null): int
    {
        $start = $from === null ? PHP_INT_MIN : self::day($from);
        $end = $to === null ? PHP_INT_MAX : self::day($to);
        $total = 0;

        foreach ($this->segments as $segment) {
            $a = max($segment['from'], $start);
            $b = min($segment['to'], $end);

            if ($a <= $b) {
                $total += self::sum($segment['week'], $a, $b);
            }
        }

        foreach ($this->overrides as $day => $minutes) {
            if ($day >= $start && $day <= $end) {
                $total += $minutes - $this->base($day);
            }
        }

        return $total;
    }

    /**
     * Minutos de capacidad por fecha (AAAA-MM-DD), en orden.
     *
     * @return array<string, int>
     */
    public function byDate(): array
    {
        $capacity = [];

        foreach ($this->segments as $segment) {
            $week = $segment['week'];
            $weekday = self::weekday($segment['from']) - 1;

            for ($day = $segment['from']; $day <= $segment['to']; $day++) {
                $capacity[gmdate('Y-m-d', $day * 86400)] = $this->overrides[$day] ?? $week[$weekday];
                $weekday = $weekday === 6 ? 0 : $weekday + 1;
            }
        }

        return $capacity;
    }

    /**
     * Minutos de la semana de su tramo en un día (sin festivos ni ausencias), o 0 fuera del plan.
     */
    public function base(int $day): int
    {
        foreach ($this->segments as $segment) {
            if ($day >= $segment['from'] && $day <= $segment['to']) {
                return $segment['week'][self::weekday($day) - 1];
            }
        }

        return 0;
    }

    /**
     * Suma de una semana de minutos entre dos días: las semanas completas y los días que sobran,
     * empezando por el día de la semana de $from.
     *
     * @param  list<int>  $week
     */
    private static function sum(array $week, int $from, int $to): int
    {
        $days = $to - $from + 1;
        $total = intdiv($days, 7) * array_sum($week);
        $weekday = self::weekday($from) - 1;

        for ($i = 0, $rest = $days % 7; $i < $rest; $i++) {
            $total += $week[($weekday + $i) % 7];
        }

        return $total;
    }
}
