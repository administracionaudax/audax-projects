<?php

use App\Domain\Billing\Holded\HoldedPayload;

/*
| Lectura de los importes de Holded (Fase 12, F1). La API v2 real los da como texto con coma decimal
| («1275,00»), comprobado contra Holded el 08/10/2026; los datos simulados de los tests, con punto.
*/

it('lee los importes con coma decimal, con puntos de miles y con punto decimal', function (mixed $value, ?string $expected) {
    expect(HoldedPayload::money(['total' => $value], 'total'))->toBe($expected);
})->with([
    'coma (formato real de la v2)' => ['1542,75', '1542.75'],
    'miles con punto' => ['1.275,00', '1275.00'],
    'negativo (rectificativa)' => ['-1034,55', '-1034.55'],
    'punto decimal' => ['1275.00', '1275.00'],
    'número' => [60, '60.00'],
    'cero' => ['0,00', '0.00'],
    'texto' => ['n/d', null],
    'vacío' => ['', null],
    'nulo' => [null, null],
]);

it('lee las unidades con coma decimal', function () {
    expect(HoldedPayload::decimal(['units' => '25,00'], 4, 'units'))->toBe('25.0000')
        ->and(HoldedPayload::decimal(['units' => '0,5'], 4, 'units'))->toBe('0.5000');
});
