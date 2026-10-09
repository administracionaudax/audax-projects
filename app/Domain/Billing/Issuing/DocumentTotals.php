<?php

namespace App\Domain\Billing\Issuing;

use RoundingMode;

/**
 * Totales de una factura (PLAN-EMISION §4.2, «Redondeo»; D-422), con bcmath y nunca float, igual que
 * `resources/js/components/invoicing/totals.ts` (casos compartidos en
 * `tests/fixtures/billing/totals.json`):
 *
 * - Bruto de una línea = cantidad × precio, al céntimo. Base = cantidad × precio × (1 − descuento %),
 *   al céntimo. Descuento de la línea = bruto − base.
 * - Base por tipo = suma de las bases de sus líneas. Cuota por tipo = base × tipo / 100, al céntimo,
 *   solo en las operaciones que llevan cuota (S1); las demás (no sujetas, exentas, inversión del
 *   sujeto pasivo) van con cuota 0.
 * - Retención (IRPF), si la hay = base total × tipo / 100, al céntimo.
 * - Total = base + cuotas − retención. El registro de VeriFactu lleva base + cuotas (sin la retención).
 *
 * Redondeo: mitad hacia arriba alejándose del cero (bcround, RoundingMode::HalfAwayFromZero), así una
 * rectificativa por el total da exactamente los mismos importes en negativo.
 *
 * @phpstan-type TotalsLine array{quantity: string, unit_price: string, discount_pct: string, tax: array{key: string, rate: string, operation_type: string}|null}
 * @phpstan-type TotalsTax array{key: string, operation_type: string, rate: string, base: string, tax: string}
 * @phpstan-type Totals array{lines: list<array{gross: string, base: string}>, subtotal: string, discount_total: string, taxes: list<TotalsTax>, tax_total: string, withholding_total: string, gross_total: string, total: string}
 */
final class DocumentTotals
{
    private const int SCALE = 10;

    /**
     * @param  list<TotalsLine>  $lines
     * @return Totals
     */
    public static function compute(array $lines, ?string $withholdingRate = null): array
    {
        $rows = [];
        $subtotal = '0';
        $discount = '0';
        /** @var array<string, TotalsTax> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $product = bcmul(self::num($line['quantity']), self::num($line['unit_price']), self::SCALE);
            $factor = bcdiv(bcsub('100', self::num($line['discount_pct']), self::SCALE), '100', self::SCALE);
            $gross = self::round($product);
            $base = self::round(bcmul(self::num($product), self::num($factor), self::SCALE));

            $rows[] = ['gross' => $gross, 'base' => $base];
            $subtotal = self::num(bcadd($subtotal, $base, 2));
            $discount = self::num(bcadd($discount, bcsub($gross, $base, 2), 2));

            if ($line['tax'] !== null) {
                $key = $line['tax']['key'];
                $groups[$key] ??= ['key' => $key, 'operation_type' => $line['tax']['operation_type'], 'rate' => self::fixed($line['tax']['rate']), 'base' => '0.00', 'tax' => '0.00'];
                $groups[$key]['base'] = self::fixed(bcadd(self::num($groups[$key]['base']), $base, 2));
            }
        }

        $taxTotal = '0.00';
        foreach ($groups as $key => $group) {
            $groups[$key]['tax'] = $group['operation_type'] === 'S1'
                ? self::round(bcdiv(bcmul(self::num($group['base']), self::num($group['rate']), self::SCALE), '100', self::SCALE))
                : '0.00';
            $taxTotal = self::num(bcadd($taxTotal, self::num($groups[$key]['tax']), 2));
        }

        // Tipos de mayor a menor, como el cuadro del PDF.
        $taxes = array_values($groups);
        usort($taxes, fn (array $a, array $b): int => bccomp(self::num($b['rate']), self::num($a['rate']), 2) ?: strcmp($a['operation_type'], $b['operation_type']) ?: strcmp($a['key'], $b['key']));

        $withholding = $withholdingRate === null || bccomp(self::num($withholdingRate), '0', 2) === 0
            ? '0.00'
            : self::round(bcdiv(bcmul($subtotal, self::num($withholdingRate), self::SCALE), '100', self::SCALE));
        $gross = self::num(bcadd($subtotal, $taxTotal, 2));

        return [
            'lines' => $rows,
            'subtotal' => self::fixed($subtotal),
            'discount_total' => self::fixed($discount),
            'taxes' => $taxes,
            'tax_total' => self::fixed($taxTotal),
            'withholding_total' => $withholding,
            'gross_total' => self::fixed($gross),
            'total' => self::fixed(bcsub($gross, $withholding, 2)),
        ];
    }

    /**
     * Al céntimo, mitad hacia arriba alejándose del cero.
     *
     * @return numeric-string
     */
    public static function round(string $value): string
    {
        $rounded = bcround(self::num($value), 2, RoundingMode::HalfAwayFromZero);

        return self::fixed($rounded === '-0.00' ? '0' : $rounded);
    }

    /**
     * Dos decimales siempre («-0.00» pasa a «0.00»).
     *
     * @return numeric-string
     */
    public static function fixed(string $value): string
    {
        $fixed = bcadd(self::num($value), '0', 2);

        return self::num($fixed === '-0.00' ? '0.00' : $fixed);
    }

    /**
     * Un importe como texto numérico (lo que no lo es, 0).
     *
     * @return numeric-string
     */
    public static function num(string $value): string
    {
        $value = trim($value);

        return is_numeric($value) ? $value : '0';
    }
}
