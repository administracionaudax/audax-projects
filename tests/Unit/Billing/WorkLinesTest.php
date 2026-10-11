<?php

use App\Domain\Billing\Issuing\DocumentTotals;
use App\Domain\Billing\Work\WorkLines;
use App\Domain\Reports\Money;
use App\Models\HourBank;

/*
| Las líneas que salen del trabajo (E3), al céntimo: la cantidad de horas que da exactamente el
| valor de las entradas y la línea «bolsadehoras» como la factura Audax hoy (D-396).
*/

it('la cantidad de horas da exactamente el valor de las entradas al céntimo', function (int $minutes, string $rate) {
    $amount = Money::round(Money::forMinutes($minutes, $rate));
    $quantity = WorkLines::quantityFor($minutes, $rate, $amount);

    expect(DocumentTotals::lineBase($quantity, $rate))->toBe($amount)
        // Las horas que se ven (dos decimales) son las de verdad.
        ->and(round((float) $quantity, 2))->toBe(round($minutes / 60, 2));
})->with([
    '20 min a 65 €/h (0,3333 h daría 21,66 €)' => [20, '65'],
    '7 min a 70 €/h' => [7, '70'],
    '1 h a 60 €/h' => [60, '60'],
    '8 h 25 min a 42,50 €/h' => [505, '42.50'],
    '137 h 10 min a 58,75 €/h' => [8230, '58.75'],
    '5 min a 99,99 €/h' => [5, '99.99'],
]);

it('sin tarifa, las horas tal cual (precio 0)', function () {
    expect(WorkLines::quantityFor(90, '0', '0.00'))->toBe('1.5000');
});

it('la bolsa: horas × €/h, con el descuento que da su precio o el €/h de su precio', function () {
    $bank = fn (int $hours, string $price, ?string $rate): HourBank => (new HourBank)->forceFill(['total_minutes' => $hours * 60, 'price_amount' => $price, 'hourly_rate' => $rate]);

    // 100 h a 60 € con un 15 % = 5.100 € (como en Holded).
    expect(WorkLines::bank($bank(100, '5100.00', '60.00')))->toBe(['quantity' => '100.0000', 'unit' => 'hour', 'unit_price' => '60.0000', 'discount_pct' => '15.00'])
        // 25 h a 51 € sin descuento.
        ->and(WorkLines::bank($bank(25, '1275.00', '51.00')))->toBe(['quantity' => '25.0000', 'unit' => 'hour', 'unit_price' => '51.0000', 'discount_pct' => '0.00'])
        // Sin tarifa: el precio entre las horas (30 h × 42,50 €).
        ->and(WorkLines::bank($bank(30, '1275.00', null)))->toBe(['quantity' => '30.0000', 'unit' => 'hour', 'unit_price' => '42.5000', 'discount_pct' => '0.00']);

    // Un precio que no cuadra al céntimo con horas × €/h: una unidad por el precio.
    $odd = WorkLines::bank($bank(3, '100.00', null));
    expect(DocumentTotals::lineBase($odd['quantity'], $odd['unit_price'], $odd['discount_pct']))->toBe('100.00');
});
