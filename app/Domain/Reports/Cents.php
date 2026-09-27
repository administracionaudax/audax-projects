<?php

namespace App\Domain\Reports;

use Generator;

/**
 * Reparto de céntimos para que las líneas de un informe sumen exactamente su total (R2, exportación
 * para facturar). Los importes exactos (6 decimales, Money) se redondean una sola vez en el total y
 * cada línea recibe su parte en céntimos: la página y el fichero enseñan el mismo total y sus
 * líneas siempre suman ese total. Solo para importes ≥ 0 (ingresos).
 */
final class Cents
{
    /**
     * Resto mayor: cada importe se trunca a céntimos y los céntimos que faltan hasta el total
     * redondeado van a los de mayor resto (a igualdad, al primero).
     *
     * @template K of array-key
     *
     * @param  array<K, string>  $exact  Importes exactos.
     * @return array<K, numeric-string> Importes con 2 decimales que suman Money::round(Σ $exact).
     */
    public static function largestRemainder(array $exact): array
    {
        if ($exact === []) {
            return [];
        }

        $total = (int) bcmul(Money::round(Money::add(...array_values($exact))), '100', 0);
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

        for ($left = $total - array_sum($cents), $i = 0; $left > 0; $left--, $i++) {
            $cents[$keys[$i % count($keys)]]++;
        }

        return array_map(fn (int $value): string => bcdiv((string) $value, '100', 2), $cents);
    }

    /**
     * En streaming (miles de filas sin cargarlas): cada línea es lo acumulado exacto redondeado
     * menos lo ya repartido, así que las líneas suman el acumulado redondeado y ninguna se aleja
     * más de un céntimo de su importe exacto. Con $target (el total calculado aparte, p. ej. el del
     * resumen por grupos, que trunca a 6 decimales por grupo y no por entrada), la última línea con
     * importe recoge la diferencia de céntimos que pueda haber: las líneas suman $target.
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): (string|null)  $exactOf  Importe exacto de cada elemento, o null si no lleva.
     * @return Generator<int, array{0: T, 1: numeric-string|null}> Cada elemento con su importe (2 decimales).
     */
    public static function running(iterable $items, callable $exactOf, ?string $target = null): Generator
    {
        $sum = '0';
        $shown = '0.00';
        /** @var list<array{0: T, 1: numeric-string|null}> $held desde la última línea con importe */
        $held = [];

        foreach ($items as $item) {
            $exact = $exactOf($item);
            $line = null;

            if ($exact !== null) {
                $sum = Money::add($sum, $exact);
                $upTo = Money::round($sum);
                $line = bcsub($upTo, $shown, 2);
                $shown = $upTo;

                if (bccomp(Money::of($exact), '0', Money::SCALE) > 0) {
                    foreach ($held as $pending) {
                        yield $pending;
                    }
                    $held = [[$item, $line]];

                    continue;
                }
            }

            if ($held === []) {
                yield [$item, $line];
            } else {
                $held[] = [$item, $line];
            }
        }

        if ($target !== null && $held !== [] && $held[0][1] !== null) {
            $held[0][1] = bcadd($held[0][1], bcsub(Money::round($target), $shown, 2), 2);
        }

        foreach ($held as $pending) {
            yield $pending;
        }
    }
}
