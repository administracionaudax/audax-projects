<?php

namespace App\Domain\Reports;

use Generator;

/**
 * Reparto de céntimos para que las líneas de un informe sumen exactamente su total (INT-04,
 * PERF-02). Los importes exactos (6 decimales, Money) se redondean una sola vez en el total y cada
 * línea recibe su parte en céntimos: la página y el fichero enseñan el mismo total y sus líneas
 * siempre suman ese total. Solo reparte diferencias de redondeo (menos de un céntimo por línea).
 */
final class Cents
{
    /**
     * Resto mayor: cada importe se trunca a céntimos y los céntimos que faltan hasta el total
     * redondeado van a los de mayor resto (a igualdad, al primero). Con $total (el total canónico
     * redondeado, RevenueCalculator), se reparte ese total; si no, el redondeo de la suma de las
     * líneas. Si sobrase algún céntimo (un total por debajo de la suma truncada), se quita a las de
     * menor resto.
     *
     * @template K of array-key
     *
     * @param  array<K, string>  $exact  Importes exactos.
     * @param  string|null  $total  Total con 2 decimales que deben sumar.
     * @return array<K, numeric-string> Importes con 2 decimales que suman $total (o Money::round(Σ $exact)).
     */
    public static function largestRemainder(array $exact, ?string $total = null): array
    {
        if ($exact === []) {
            return [];
        }

        $target = (int) bcmul(Money::round($total ?? Money::add(...array_values($exact))), '100', 0);
        $cents = [];
        $remainders = [];
        foreach ($exact as $key => $amount) {
            $scaled = bcmul(Money::of($amount), '100', Money::SCALE);
            $cents[$key] = (int) bcadd($scaled, '0', 0);
            $remainders[$key] = bcsub($scaled, (string) $cents[$key], Money::SCALE);
        }

        $keys = array_keys($exact);
        // usort es estable: a igualdad de resto, el orden original.
        usort($keys, fn (int|string $a, int|string $b): int => bccomp($remainders[$b], $remainders[$a], Money::SCALE));

        $left = $target - array_sum($cents);
        for ($i = 0; $left > 0; $left--, $i++) {
            $cents[$keys[$i % count($keys)]]++;
        }

        $ascending = array_reverse($keys);
        while ($left < 0) {
            $removed = false;
            foreach ($ascending as $key) {
                if ($left < 0 && $cents[$key] > 0) {
                    $cents[$key]--;
                    $left++;
                    $removed = true;
                }
            }
            if (! $removed) {
                // Sin céntimos que quitar (un total negativo): la diferencia, a la primera línea.
                $cents[$keys[0]] += $left;
                $left = 0;
            }
        }

        return array_map(fn (int $value): string => bcdiv((string) $value, '100', 2), $cents);
    }

    /**
     * En streaming (miles de filas sin cargarlas): cada línea es lo acumulado exacto redondeado
     * menos lo ya repartido, así que las líneas suman el acumulado redondeado y ninguna se aleja
     * más de un céntimo de su importe exacto. Con los importes de EntryValuation::next (la parte
     * de cada entrada del total canónico), las líneas suman el total del resumen.
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): (string|null)  $exactOf  Importe exacto de cada elemento, o null si no lleva.
     * @return Generator<int, array{0: T, 1: numeric-string|null}> Cada elemento con su importe (2 decimales).
     */
    public static function running(iterable $items, callable $exactOf): Generator
    {
        $running = new RunningCents;

        foreach ($items as $item) {
            yield [$item, $running->next($exactOf($item))];
        }
    }
}
