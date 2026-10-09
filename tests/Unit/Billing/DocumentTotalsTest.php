<?php

use App\Domain\Billing\Issuing\DocumentTotals;

/*
| T-TOT (PLAN-EMISION §8.2; D-422): los totales de una factura, con los mismos casos que el editor
| (tests/fixtures/billing/totals.json, también en Vitest).
*/

$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/billing/totals.json'), true);

it('calcula bases, desglose, retención y total como el caso compartido', function (array $case) {
    expect(DocumentTotals::compute($case['lines'], $case['withholding']))->toBe($case['expected']);
})->with(array_combine(
    array_map(fn (array $case): string => $case['name'], $fixture['cases']),
    array_map(fn (array $case): array => [$case], $fixture['cases']),
));

it('una rectificativa por el total da exactamente el negativo de la factura', function () {
    $lines = [
        ['quantity' => '7.5', 'unit_price' => '61.333', 'discount_pct' => '3.5', 'tax' => ['key' => '1', 'rate' => '21', 'operation_type' => 'S1']],
        ['quantity' => '1', 'unit_price' => '0.005', 'discount_pct' => '0', 'tax' => ['key' => '2', 'rate' => '10', 'operation_type' => 'S1']],
    ];
    $negated = array_map(fn (array $line): array => [...$line, 'quantity' => '-'.$line['quantity']], $lines);

    $invoice = DocumentTotals::compute($lines, '15');
    $credit = DocumentTotals::compute($negated, '15');

    foreach (['subtotal', 'tax_total', 'withholding_total', 'gross_total', 'total'] as $field) {
        expect($credit[$field])->toBe(DocumentTotals::fixed(bcmul($invoice[$field], '-1', 2)));
    }
});
