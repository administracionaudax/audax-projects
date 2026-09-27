<?php

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use Illuminate\Support\Arr;
use Tests\Feature\Reports\R3Scenario;

/*
| Metrics::hours (añadido por R3): los totales de horas con las mismas definiciones que
| Metrics::summary(), sin capacidad, estimación ni importes, salvo «dentro de bolsa», que solo cuenta
| las entradas con bolsa. Cifras del escenario a mano (R3Scenario, semana del 21 al 27/09/2026).
*/

beforeEach(function () {
    $this->s = R3Scenario::build($this);
    $this->scope = fn ($viewer, array $query = []): ReportScope => new ReportScope($viewer, ReportFilters::fromQuery(R3Scenario::week($query)));
});

it('da las horas como a mano y coincide con summary() en cada alcance', function (string $who, array $query, array $expected) {
    $s = $this->s;
    $viewer = match ($who) {
        'admin' => $s->admin,
        'empleada' => $s->ana,
    };
    $query = array_map(fn ($value) => is_string($value) && property_exists($s, $value) ? [$s->{$value}->id] : $value, $query);
    $scope = ($this->scope)($viewer, $query);
    $metrics = app(Metrics::class);

    $hours = $metrics->hours($scope);
    $summary = $metrics->summary($scope);

    expect($hours)->toBe($expected)
        ->and(Arr::except($hours, 'in_bank_minutes'))->toBe(Arr::except(array_intersect_key($summary, $hours), 'in_bank_minutes'));
})->with([
    // 300 + 120 + 240 (Ana) + 500 + 200 + 60 (Luis) = 1420; facturables sin el interno: 1360; exceso 100.
    // Dentro de bolsa: solo la bolsa, 500 + 200 − 100 de exceso = 600 (no las 660 de Ana sin bolsa).
    'admin, Diseño' => ['admin', ['departamento' => 'design'], [
        'logged_minutes' => 1420, 'billable_minutes' => 1360, 'in_bank_minutes' => 600, 'overage_minutes' => 100, 'billability' => 0.9577,
    ]],
    // Luis: 760 imputadas, 700 facturables (700 / 760 = 0,9211), 100 de exceso; dentro, 600 (sin el interno).
    'admin, solo Luis' => ['admin', ['persona' => 'luis'], [
        'logged_minutes' => 760, 'billable_minutes' => 700, 'in_bank_minutes' => 600, 'overage_minutes' => 100, 'billability' => 0.9211,
    ]],
    // La empleada solo ve las suyas: 300 + 120 + 240, todas facturables y ninguna en una bolsa.
    'empleada' => ['empleada', [], [
        'logged_minutes' => 660, 'billable_minutes' => 660, 'in_bank_minutes' => 0, 'overage_minutes' => 0, 'billability' => 1.0,
    ]],
    // Sin horas: facturabilidad sin base.
    'sin horas' => ['admin', ['proyecto' => 'tm', 'facturable' => 'no'], [
        'logged_minutes' => 0, 'billable_minutes' => 0, 'in_bank_minutes' => 0, 'overage_minutes' => 0, 'billability' => null,
    ]],
]);

it('cuenta dentro de bolsa solo lo que va dentro de alguna bolsa, como el desglose por bolsa', function (array $query, int $expected) {
    $s = $this->s;
    $query = array_map(fn ($value) => is_string($value) && property_exists($s, $value) ? [$s->{$value}->id] : $value, $query);
    $scope = ($this->scope)($s->admin, $query);
    $metrics = app(Metrics::class);

    // Las filas de las bolsas del desglose (sin la de «Sin bolsa»): imputadas − exceso de cada una.
    $banks = array_filter($metrics->breakdown($scope, Dimension::HourBank), fn (array $row): bool => $row['key'] !== null);

    expect($metrics->hours($scope)['in_bank_minutes'])->toBe($expected)
        ->and(array_sum(array_column($banks, 'in_bank_minutes')))->toBe($expected);
})->with([
    'toda la semana' => [[], 600],
    'un proyecto sin bolsa' => [['proyecto' => 'tm'], 0],
    'solo la bolsa, sin su exceso' => [['bolsa' => 'bank'], 600],
    'solo Luis, sin el interno' => [['persona' => 'luis'], 600],
]);
