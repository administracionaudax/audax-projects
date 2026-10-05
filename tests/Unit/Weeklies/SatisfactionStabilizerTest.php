<?php

use App\Domain\Weeklies\SatisfactionStabilizer;

/*
| Port exacto de ws:supabase/functions/_shared/satisfaction.js (F-093, D-146). Los casos y lo que
| devuelve el original salen de ejecutarlo con node: tests/fixtures/weeklies/generate-satisfaction-cases.mjs.
*/

/**
 * @return array<string, mixed>
 */
function satisfactionFixture(): array
{
    static $fixture = null;

    /** @var array<string, mixed> $fixture */
    $fixture ??= json_decode((string) file_get_contents(__DIR__.'/../../fixtures/weeklies/satisfaction-cases.json'), true, flags: JSON_THROW_ON_ERROR);

    return $fixture;
}

/**
 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
 */
function satisfactionCases(): array
{
    $cases = [];

    foreach (satisfactionFixture()['stabilize'] as $case) {
        $cases[$case['name']] = [$case['input'], $case['expected']];
    }

    return $cases;
}

beforeEach(fn () => $this->stabilizer = new SatisfactionStabilizer);

it('da el mismo delta, la misma regla y las mismas señales que el original', function (array $input, array $expected) {
    expect($this->stabilizer->stabilize($input)->toArray())->toBe($expected);
})->with(satisfactionCases());

it('cubre todas las reglas del original', function () {
    $rules = array_unique(array_map(fn (array $case): string => $case['expected']['rule'], satisfactionFixture()['stabilize']));
    sort($rules);

    expect($rules)->toBe([
        'accepted', 'dampened', 'guarded-to-stable', 'no-explicit-client-impact',
        'routine-week-no-change', 'too-little-information', 'zero-or-invalid-request',
    ])->and(count(satisfactionFixture()['stabilize']))->toBeGreaterThan(300);
});

it('explica el delta como el original', function () {
    foreach (satisfactionFixture()['reasoning'] as $case) {
        [$base, $delta, $rule] = $case['input'];

        expect($this->stabilizer->stableReasoning($base, $delta, $rule))->toBe($case['expected']);
    }
});

it('limpia las vallas de una respuesta JSON como el original', function () {
    foreach (satisfactionFixture()['cleanJson'] as $case) {
        expect(SatisfactionStabilizer::cleanJsonResponse($case['input']))->toBe($case['expected']);
    }
});

it('calcula el tono, la evidencia y la confianza como el original', function () {
    foreach (satisfactionFixture()['tone'] as $case) {
        expect($this->stabilizer->toneScore($case['input']))->toBe($case['expected']);
    }

    foreach (satisfactionFixture()['evidence'] as $case) {
        expect($this->stabilizer->normalizeEvidenceLevel($case['input']))->toBe($case['expected']);
    }

    foreach (satisfactionFixture()['confidence'] as $case) {
        $actual = $this->stabilizer->normalizeConfidence(['value' => $case['input']], 'value');

        expect($actual === null ? null : $actual + 0)->toEqual($case['expected']);
    }
});

it('acota la nueva puntuación entre 0 y 100', function () {
    $up = $this->stabilizer->stabilize([
        'requestedDelta' => 8,
        'currentSatisfaction' => 50,
        'reportText' => 'Esta semana hemos entregado la nueva web y el cliente dio el ok, se ha publicado la landing y quedó muy satisfecho con todo',
        'evidenceLevel' => 'HIGH',
        'explicitClientImpact' => true,
        'confidence' => 0.9,
    ]);

    expect($up->finalDelta)->toBe(8)
        ->and($up->applyTo(50))->toBe(58)
        ->and($up->applyTo(97))->toBe(100)
        ->and((new SatisfactionStabilizer)->stabilize(['requestedDelta' => -3])->applyTo(2))->toBe(2);
});
