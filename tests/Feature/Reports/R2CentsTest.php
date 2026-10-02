<?php

use App\Domain\Reports\Cents;
use App\Domain\Reports\RunningCents;

/*
| Reparto de céntimos (Cents y RunningCents, INT-04 y PERF-02): las líneas siempre suman el total
| redondeado una sola vez y solo se reparten diferencias de redondeo.
*/

it('reparte por resto mayor: a igualdad de resto, el primero', function () {
    expect(Cents::largestRemainder(['a' => '1.944444', 'b' => '1.944444']))->toBe(['a' => '1.95', 'b' => '1.94'])
        ->and(Cents::largestRemainder(['0.333333', '0.333333', '0.333333']))->toBe(['0.34', '0.33', '0.33'])
        ->and(Cents::largestRemainder(['116.000000', '1221.666666']))->toBe(['116.00', '1221.67'])
        ->and(Cents::largestRemainder(['0', '2.005']))->toBe(['0.00', '2.01'])
        ->and(Cents::largestRemainder([]))->toBe([]);
});

it('reparte un total dado (el canónico de RevenueCalculator), por encima o por debajo de la suma de las líneas', function () {
    // Tres líneas de 0,001666 (truncadas) suman 0,004998 → 0,00; el total de su unidad es 0,005 → 0,01.
    expect(Cents::largestRemainder(['0.001666', '0.001666', '0.001666'], '0.01'))->toBe(['0.01', '0.00', '0.00'])
        // Si sobra un céntimo, se quita a la de menor resto (a igualdad, la última).
        ->and(Cents::largestRemainder(['1.00', '2.00'], '2.99'))->toBe(['1.00', '1.99'])
        ->and(Cents::largestRemainder(['0.004', '0.003'], '0.00'))->toBe(['0.00', '0.00']);
});

it('en streaming, cada línea es lo acumulado redondeado menos lo ya repartido', function () {
    $lines = iterator_to_array(Cents::running(['1.944444', null, '1.944444', '0'], fn (?string $amount): ?string => $amount), false);

    // 1,944444 → 1,94; 3,888888 → 3,89 (+1,95); la línea sin importe no lleva; la de 0, 0,00.
    expect(array_column($lines, 1))->toBe(['1.94', null, '1.95', '0.00']);
});

it('RunningCents reparte una columna: sus líneas suman el acumulado redondeado una vez', function () {
    $running = new RunningCents;

    expect([$running->next('0.001666'), $running->next('0.001667'), $running->next('0.001667'), $running->next(null)])
        ->toBe(['0.00', '0.00', '0.01', null])
        ->and($running->total())->toBe('0.01');
});
