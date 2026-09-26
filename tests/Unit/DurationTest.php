<?php

use App\Support\Duration;

/** @return array<int, array{0: string, 1: int|null}> */
function sharedDurationCases(): array
{
    /** @var array{cases: array<int, array{0: string, 1: int|null}>} $fixture */
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/duration-cases.json'), true);

    return $fixture['cases'];
}

it('interpreta las duraciones igual que el frontend', function (string $input, ?int $expected) {
    expect(Duration::parse($input))->toBe($expected);
})->with(sharedDurationCases());

it('formatea minutos como h:mm', function (int $minutes, string $expected) {
    expect(Duration::format($minutes))->toBe($expected);
})->with([
    [0, '0:00'],
    [5, '0:05'],
    [90, '1:30'],
    [1500, '25:00'],
    [-30, '-0:30'],
]);

it('redondea al múltiplo más cercano', function (int $minutes, int $step, int $expected) {
    expect(Duration::roundToNearest($minutes, $step))->toBe($expected);
})->with([
    [7, 1, 7],
    [7, 5, 5],
    [8, 5, 10],
    [2, 15, 0],
    [8, 15, 15],
    [52, 15, 45],
    [53, 15, 60],
]);

it('admite un máximo mayor para estimaciones y bolsas', function (string $input, int $max, ?int $expected) {
    expect(Duration::parse($input, $max))->toBe($expected);
})->with(function (): array {
    /** @var array{with_max: array<int, array{0: string, 1: int, 2: int|null}>} $fixture */
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/duration-cases.json'), true);

    return $fixture['with_max'];
});
