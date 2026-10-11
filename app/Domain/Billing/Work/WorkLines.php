<?php

namespace App\Domain\Billing\Work;

use App\Domain\Billing\Issuing\DocumentTotals;
use App\Models\HourBank;

/**
 * Las cuentas de las líneas que salen del trabajo (PLAN-EMISION §3.8; E3), al céntimo y sin float:
 *
 * - **Horas** (y exceso de bolsa): cantidad = horas (4 decimales), precio = la tarifa congelada.
 *   La base de la línea tiene que ser exactamente el valor de sus entradas (EntryValuation): si
 *   horas × tarifa no da ese importe al redondear (20 min a 65 €/h son 0,3333 h × 65 = 21,66 €,
 *   y valen 21,67 €), se usa la cantidad más cercana que sí lo da (0,3334). La diferencia está en el
 *   cuarto decimal de las horas; en el PDF se ven las mismas horas con dos decimales.
 * - **Bolsa vendida** (como Audax factura hoy en Holded, D-396): «bolsadehoras» con unidades = horas
 *   de la bolsa y precio = €/h. Si la bolsa tiene tarifa y su precio es menor que horas × tarifa,
 *   precio = tarifa con el descuento que da el precio (100 h × 60 € −15 % = 5.100 €); si no, precio =
 *   precio de la bolsa entre sus horas. Si ninguna de las dos cuadra al céntimo, una unidad por el
 *   precio de la bolsa.
 */
final class WorkLines
{
    /** Cuánto se aleja como mucho la cantidad de minutos / 60 (en diezmilésimas de hora). */
    private const int NUDGE = 60;

    /**
     * La cantidad en horas (4 decimales) para unos minutos a un precio, con la que la base es
     * exactamente el importe.
     *
     * @return numeric-string
     */
    public static function quantityFor(int $minutes, string $price, string $amount): string
    {
        $exact = bcdiv((string) $minutes, '60', 10);
        $base = self::round4($exact);
        if (bccomp(DocumentTotals::num($price), '0', 4) === 0) {
            return $base;
        }

        $target = DocumentTotals::fixed($amount);
        for ($step = 0; $step <= self::NUDGE; $step++) {
            foreach ($step === 0 ? [0] : [$step, -$step] as $delta) {
                $quantity = bcadd($base, bcmul((string) $delta, '0.0001', 4), 4);
                if (DocumentTotals::lineBase($quantity, $price) === $target) {
                    return $quantity;
                }
            }
        }

        return $base;
    }

    /**
     * La línea de una bolsa vendida.
     *
     * @return array{quantity: string, unit: string, unit_price: string, discount_pct: string}
     */
    public static function bank(HourBank $bank): array
    {
        $price = DocumentTotals::fixed((string) $bank->price_amount);
        $hours = self::round4(bcdiv((string) $bank->total_minutes, '60', 10));
        $rate = $bank->hourly_rate !== null ? DocumentTotals::num((string) $bank->hourly_rate) : null;

        if (bccomp($hours, '0', 4) > 0) {
            if ($rate !== null && bccomp($rate, '0', 4) > 0) {
                $list = bcmul($hours, $rate, 10);
                if (bccomp($list, $price, 2) === 0) {
                    return ['quantity' => $hours, 'unit' => 'hour', 'unit_price' => self::round4($rate), 'discount_pct' => '0.00'];
                }
                if (bccomp($list, $price, 2) > 0) {
                    $discount = DocumentTotals::round(bcmul(bcsub('1', bcdiv($price, $list, 10), 10), '100', 10));
                    if (DocumentTotals::lineBase($hours, $rate, $discount) === $price) {
                        return ['quantity' => $hours, 'unit' => 'hour', 'unit_price' => self::round4($rate), 'discount_pct' => $discount];
                    }
                }
            }

            $unit = self::round4(bcdiv($price, $hours, 10));
            if (DocumentTotals::lineBase($hours, $unit) === $price) {
                return ['quantity' => $hours, 'unit' => 'hour', 'unit_price' => $unit, 'discount_pct' => '0.00'];
            }
        }

        return ['quantity' => '1.0000', 'unit' => 'unit', 'unit_price' => self::round4($price), 'discount_pct' => '0.00'];
    }

    /**
     * Cuatro decimales, mitad hacia arriba.
     *
     * @return numeric-string
     */
    public static function round4(string $value): string
    {
        return DocumentTotals::num(bcadd(bcround(DocumentTotals::num($value), 4, \RoundingMode::HalfAwayFromZero), '0', 4));
    }
}
