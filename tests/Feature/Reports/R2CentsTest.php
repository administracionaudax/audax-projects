<?php

use App\Domain\Reports\Cents;

/*
| Reparto de céntimos (Cents, R2): las líneas siempre suman el total redondeado una sola vez.
*/

it('reparte por resto mayor: a igualdad de resto, el primero', function () {
    expect(Cents::largestRemainder(['a' => '1.944444', 'b' => '1.944444']))->toBe(['a' => '1.95', 'b' => '1.94'])
        ->and(Cents::largestRemainder(['0.333333', '0.333333', '0.333333']))->toBe(['0.34', '0.33', '0.33'])
        ->and(Cents::largestRemainder(['116.000000', '1221.666666']))->toBe(['116.00', '1221.67'])
        ->and(Cents::largestRemainder(['0', '2.005']))->toBe(['0.00', '2.01'])
        ->and(Cents::largestRemainder([]))->toBe([]);
});

it('en streaming, cada línea es lo acumulado redondeado menos lo ya repartido', function () {
    $lines = iterator_to_array(Cents::running(['1.944444', null, '1.944444', '0'], fn (?string $amount): ?string => $amount), false);

    // 1,944444 → 1,94; 3,888888 → 3,89 (+1,95); la línea sin importe no lleva; la de 0, 0,00.
    expect(array_column($lines, 1))->toBe(['1.94', null, '1.95', '0.00']);
});

it('con un total de referencia, la última línea con importe recoge la diferencia', function () {
    $items = ['0.001666', '0.001666', '0.001666', '0', null];
    $lines = iterator_to_array(Cents::running($items, fn (?string $amount): ?string => $amount, '0.01'), false);

    // Sumadas darían 0,004998 → 0,00; el total del resumen es 0,01: va a la tercera, no a la de 0.
    expect(array_column($lines, 0))->toBe($items)
        ->and(array_column($lines, 1))->toBe(['0.00', '0.00', '0.01', '0.00', null]);
});
