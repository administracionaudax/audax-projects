<?php

use App\Domain\Billing\Issuing\DocumentTotals;
use App\Domain\Billing\Issuing\RecordHasher;
use Carbon\CarbonImmutable;

/*
| T-HASH (PLAN-EMISION §8.2; D-420): la huella de un registro de facturación es la de la AEAT.
| Los tres ejemplos oficiales de las «Especificaciones técnicas para la generación de la huella o
| hash de los registros de facturación» (v0.1.2, 27/08/2024), con su resultado exacto.
*/

it('da la huella del primer registro de alta del ejemplo oficial (PrimerRegistro = S)', function () {
    $input = RecordHasher::altaInput([
        'issuer_tax_id' => '89890001K',
        'invoice_number' => '12345678/G33',
        'issue_date_text' => '01-01-2024',
        'invoice_type' => 'F1',
        'tax_total_text' => '12.35',
        'total_text' => '123.45',
        'previous_hash' => '',
        'generated_at_text' => '2024-01-01T19:20:30+01:00',
    ]);

    expect($input)->toBe('IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00')
        ->and(RecordHasher::hash($input))->toBe('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60');
});

it('da la huella del segundo registro de alta, encadenado al primero', function () {
    $hash = RecordHasher::hash(RecordHasher::altaInput([
        'issuer_tax_id' => '89890001K',
        'invoice_number' => '12345679/G34',
        'issue_date_text' => '01-01-2024',
        'invoice_type' => 'F1',
        'tax_total_text' => '12.35',
        'total_text' => '123.45',
        'previous_hash' => '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
        'generated_at_text' => '2024-01-01T19:20:35+01:00',
    ]));

    expect($hash)->toBe('F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97');
});

it('da la huella del registro de anulación del ejemplo oficial', function () {
    $input = RecordHasher::anulacionInput([
        'issuer_tax_id' => '89890001K',
        'invoice_number' => '12345679/G34',
        'issue_date_text' => '01-01-2024',
        'previous_hash' => 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
        'generated_at_text' => '2024-01-01T19:20:40+01:00',
    ]);

    expect($input)->toStartWith('IDEmisorFacturaAnulada=89890001K&NumSerieFacturaAnulada=12345679/G34&FechaExpedicionFacturaAnulada=01-01-2024&Huella=F7B9')
        ->and(RecordHasher::hash($input))->toBe('177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68');
});

it('quita los espacios de los extremos de cada valor, en mayúsculas y con 64 caracteres', function () {
    $fields = [
        'issuer_tax_id' => '89890001K',
        'invoice_number' => '12345678/G33',
        'issue_date_text' => '01-01-2024',
        'invoice_type' => 'F1',
        'tax_total_text' => '12.35',
        'total_text' => '123.45',
        'previous_hash' => '',
        'generated_at_text' => '2024-01-01T19:20:30+01:00',
    ];
    $padded = array_map(fn (string $value): string => "  {$value} ", $fields);

    $hash = RecordHasher::hash(RecordHasher::altaInput($padded));

    expect($hash)->toBe('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60')
        ->and($hash)->toMatch('/^[0-9A-F]{64}$/');
});

it('escribe los importes con dos decimales y punto, también en negativo, y la fecha y la hora de Madrid', function () {
    expect(RecordHasher::amount('123.1'))->toBe('123.10')
        ->and(RecordHasher::amount('-1210'))->toBe('-1210.00')
        ->and(RecordHasher::amount('0'))->toBe('0.00')
        ->and(RecordHasher::amount('-0.00'))->toBe('0.00')
        ->and(DocumentTotals::fixed('-0.001'))->toBe('0.00')
        ->and(RecordHasher::date(CarbonImmutable::parse('2027-01-04')))->toBe('04-01-2027')
        // Invierno (+01:00) y verano (+02:00).
        ->and(RecordHasher::timestamp(CarbonImmutable::parse('2027-01-04 09:20:30', 'UTC')))->toBe('2027-01-04T10:20:30+01:00')
        ->and(RecordHasher::timestamp(CarbonImmutable::parse('2027-07-04 09:20:30', 'UTC')))->toBe('2027-07-04T11:20:30+02:00');

    // «123.10» y «123.1» no dan la misma huella: por eso el formato es fijo.
    $base = ['issuer_tax_id' => 'B12345678', 'invoice_number' => 'F270001', 'issue_date_text' => '04-01-2027', 'invoice_type' => 'F1', 'tax_total_text' => '21.36', 'previous_hash' => '', 'generated_at_text' => '2027-01-04T10:20:30+01:00'];
    expect(RecordHasher::hash(RecordHasher::altaInput([...$base, 'total_text' => '123.10'])))
        ->not->toBe(RecordHasher::hash(RecordHasher::altaInput([...$base, 'total_text' => '123.1'])));
});

it('trata «/», «&» y las tildes del número como texto UTF-8', function () {
    $input = RecordHasher::altaInput([
        'issuer_tax_id' => 'B12345678',
        'invoice_number' => 'Ñ/2027&1',
        'issue_date_text' => '04-01-2027',
        'invoice_type' => 'R1',
        'tax_total_text' => '-21.00',
        'total_text' => '-121.00',
        'previous_hash' => str_repeat('A', 64),
        'generated_at_text' => '2027-01-04T10:20:30+01:00',
    ]);

    expect($input)->toContain('NumSerieFactura=Ñ/2027&1&FechaExpedicionFactura')
        ->and(RecordHasher::hash($input))->toBe(strtoupper(hash('sha256', $input)));
});
