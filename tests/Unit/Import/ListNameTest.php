<?php

use App\Domain\Import\ClickUp\ListName;

test('lee el patrón TIPO+N - Hh - descripción - Fxxxxxx [Cliente]', function (string $raw, string $name, ?string $code, ?int $number, ?int $hours, bool $unknown, ?string $invoice, ?string $description, ?string $client) {
    $parsed = ListName::parse($raw);

    expect($parsed)
        ->name->toBe($name)
        ->code->toBe($code)
        ->number->toBe($number)
        ->hours->toBe($hours)
        ->hoursUnknown->toBe($unknown)
        ->invoiceReference->toBe($invoice)
        ->description->toBe($description)
        ->client->toBe($client);
})->with([
    'bolsa completa' => ['👩🏻‍💻 BH4 - 10h - Desarrollo - F250309 [Cliente Uno]', 'BH4 - 10h - Desarrollo', 'BH', 4, 10, false, 'F250309', 'Desarrollo', 'Cliente Uno'],
    'bolsa con factura y sin descripción' => ['🏭 BH4 - 25h - F260115 [Taller]', 'BH4 - 25h', 'BH', 4, 25, false, 'F260115', null, 'Taller'],
    'factura de siete cifras' => ['🏨 BH7 - 100h - F2600192 [Hotel]', 'BH7 - 100h', 'BH', 7, 100, false, 'F2600192', null, 'Hotel'],
    'H mayúscula' => ['🎶 BH2 - 60H - Compensada [Música]', 'BH2 - 60h - Compensada', 'BH', 2, 60, false, null, 'Compensada', 'Música'],
    'fee con ??h' => ['🍊 FE2 - ??h - Podcast [Frutas]', 'FE2 - Podcast', 'FE', 2, null, true, null, 'Podcast', 'Frutas'],
    'guion final vacío' => ['🏡 WE1 - 185h - [Casa]', 'WE1 - 185h', 'WE', 1, 185, false, null, null, 'Casa'],
    'texto tras las horas' => ['📺 WE1 - 126h 116/136 [Tele]', 'WE1 - 126h - 116/136', 'WE', 1, 126, false, null, '116/136', 'Tele'],
    'sin horas' => ['🚀 GE1 - Chat OPS [Empresa]', 'GE1 - Chat OPS', 'GE', 1, null, false, null, 'Chat OPS', 'Empresa'],
    '0 h' => ['☠️ GE1 - 0h [Marca]', 'GE1', 'GE', 1, null, false, null, null, 'Marca'],
    'sin corchetes' => ['BH1 - 25h', 'BH1 - 25h', 'BH', 1, 25, false, null, null, null],
    'banderas y emoji compuestos' => ['🧴🇫🇷 FE2 - 15h - Skin Cap [Lab]', 'FE2 - 15h - Skin Cap', 'FE', 2, 15, false, null, 'Skin Cap', 'Lab'],
    'sin código' => ['👨🏻‍🍳 Auditoría de Marketing', 'Auditoría de Marketing', null, null, null, false, null, null, null],
    'sin código con horas entre paréntesis' => ['💎 Bolsa de horas 1 (100h)', 'Bolsa de horas 1 (100h)', null, null, null, false, null, null, null],
]);

test('quita emoji, modificadores y espacios sobrantes', function () {
    expect(ListName::stripEmoji('♻️  Sitra - SIQUIMICA '))->toBe('Sitra - SIQUIMICA')
        ->and(ListName::stripEmoji('👰🏻‍♀️ Campo Anibal'))->toBe('Campo Anibal')
        ->and(ListName::stripEmoji('🐓 H&N'))->toBe('H&N');
});

test('distingue bolsas y fees', function () {
    expect(ListName::parse('BH1 - 5h')->isHourBank())->toBeTrue()
        ->and(ListName::parse('FE1 - 5h')->isMonthlyFee())->toBeTrue()
        ->and(ListName::parse('WE1 - 5h')->isHourBank())->toBeFalse();
});
