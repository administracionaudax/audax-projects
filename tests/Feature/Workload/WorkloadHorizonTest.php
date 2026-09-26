<?php

use App\Domain\Workload\WorkloadFilters;
use App\Domain\Workload\WorkloadHorizon;
use Carbon\CarbonImmutable;

/*
| Horizontes y filtros de la vista Carga (SPEC §9, D-052): por defecto, la semana que viene.
*/

it('calcula el primer y el último día de cada horizonte desde hoy', function (string $today, WorkloadHorizon $horizon, string $from, string $to) {
    [$start, $end] = $horizon->bounds(CarbonImmutable::parse($today));

    expect([$start->toDateString(), $end->toDateString()])->toBe([$from, $to]);
})->with([
    'semana actual (martes)' => ['2026-10-06', WorkloadHorizon::CurrentWeek, '2026-10-06', '2026-10-11'],
    'semana actual (domingo)' => ['2026-10-11', WorkloadHorizon::CurrentWeek, '2026-10-11', '2026-10-11'],
    'semana que viene (martes)' => ['2026-10-06', WorkloadHorizon::NextWeek, '2026-10-12', '2026-10-18'],
    'semana que viene (domingo)' => ['2026-10-11', WorkloadHorizon::NextWeek, '2026-10-12', '2026-10-18'],
    'semana que viene (lunes)' => ['2026-10-12', WorkloadHorizon::NextWeek, '2026-10-19', '2026-10-25'],
    '4 semanas' => ['2026-10-06', WorkloadHorizon::FourWeeks, '2026-10-06', '2026-11-01'],
    '3 meses' => ['2026-10-06', WorkloadHorizon::ThreeMonths, '2026-10-06', '2027-01-03'],
    'cambio de hora (25/10)' => ['2026-10-23', WorkloadHorizon::NextWeek, '2026-10-26', '2026-11-01'],
]);

it('por defecto, la semana que viene; un valor desconocido también', function () {
    expect(WorkloadHorizon::fromQuery(null))->toBe(WorkloadHorizon::NextWeek)
        ->and(WorkloadHorizon::fromQuery('semana-actual'))->toBe(WorkloadHorizon::CurrentWeek)
        ->and(WorkloadHorizon::fromQuery('ayer'))->toBe(WorkloadHorizon::NextWeek)
        ->and(WorkloadHorizon::fromQuery(['3-meses']))->toBe(WorkloadHorizon::NextWeek)
        ->and(WorkloadHorizon::ThreeMonths->byWeek())->toBeTrue()
        ->and(WorkloadHorizon::FourWeeks->byWeek())->toBeFalse();
});

it('lee los filtros de la URL e ignora lo que no es válido', function () {
    $filters = WorkloadFilters::fromQuery([
        'horizonte' => '4-semanas',
        'departamento' => ['3', 'x', '-1', '3'],
        'persona' => '7,5',
        'cliente' => ['2'],
        'proyecto' => [9, '0'],
        'celda' => '12:2026-10-13',
    ]);

    expect($filters->horizon)->toBe(WorkloadHorizon::FourWeeks)
        ->and($filters->departmentIds)->toBe([3])
        ->and($filters->userIds)->toBe([5, 7])
        ->and($filters->plannerFilters())->toBe(['project_ids' => [9], 'client_ids' => [2]])
        ->and($filters->hasCell())->toBeTrue()
        ->and([$filters->cellUserId, $filters->cellDate])->toBe([12, '2026-10-13'])
        ->and($filters->toQuery())->toBe(['horizonte' => '4-semanas', 'departamento' => [3], 'persona' => [5, 7], 'cliente' => [2], 'proyecto' => [9]])
        ->and($filters->withoutPeopleFilters()->toQuery())->toBe(['horizonte' => '4-semanas', 'cliente' => [2], 'proyecto' => [9]]);
});

it('una celda mal formada o con una fecha imposible se ignora', function (string $cell) {
    expect(WorkloadFilters::fromQuery(['celda' => $cell])->hasCell())->toBeFalse();
})->with(['12', '12:2026-02-30', 'x:2026-10-13', '12:13/10/2026', '12:2026-10-13:extra']);
