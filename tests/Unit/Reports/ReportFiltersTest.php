<?php

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/*
| Filtros globales de los informes (SPEC §10): en la URL, en español y tolerantes a valores malos.
*/

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-09-25'); // viernes
});

it('por defecto es el mes actual', function () {
    $f = ReportFilters::fromQuery([], $this->today);

    expect($f->period)->toBe(ReportPeriod::Month)
        ->and($f->from->toDateString())->toBe('2026-09-01')
        ->and($f->to->toDateString())->toBe('2026-09-30')
        ->and($f->days())->toBe(30);
});

it('calcula semana (lunes a domingo), trimestre y año a partir del ancla', function (string $period, string $anchor, string $from, string $to) {
    $f = ReportFilters::fromQuery(['periodo' => $period, 'fecha' => $anchor], $this->today);

    expect($f->from->toDateString())->toBe($from)->and($f->to->toDateString())->toBe($to);
})->with([
    ['semana', '2026-09-25', '2026-09-21', '2026-09-27'],
    ['semana', '2026-09-27', '2026-09-21', '2026-09-27'],
    ['trimestre', '2026-08-15', '2026-07-01', '2026-09-30'],
    ['anio', '2026-03-10', '2026-01-01', '2026-12-31'],
    ['mes', '2026-02-10', '2026-02-01', '2026-02-28'],
]);

it('acepta un rango válido y descarta los inválidos o demasiado largos', function () {
    $ok = ReportFilters::fromQuery(['periodo' => 'rango', 'desde' => '2026-09-03', 'hasta' => '2026-09-17'], $this->today);
    expect($ok->period)->toBe(ReportPeriod::Range)->and($ok->days())->toBe(15);

    foreach ([
        ['desde' => '2026-09-17', 'hasta' => '2026-09-03'],
        ['desde' => '2026-02-30', 'hasta' => '2026-03-03'],
        ['desde' => '2020-01-01', 'hasta' => '2026-01-01'],
        ['desde' => 'ayer', 'hasta' => '2026-01-01'],
    ] as $range) {
        $f = ReportFilters::fromQuery(['periodo' => 'rango', ...$range], $this->today);
        expect($f->period)->toBe(ReportPeriod::Month)->and($f->from->toDateString())->toBe('2026-09-01');
    }
});

it('lee los identificadores sin repetir, ordenados y descartando basura', function () {
    $f = ReportFilters::fromQuery([
        'persona' => ['3', '1', '3', 'x', '-2', '0'],
        'proyecto' => '7,5',
        'cliente' => [['anidado']],
        'facturable' => 'no',
        'comparar' => '1',
    ], $this->today);

    expect($f->userIds)->toBe([1, 3])
        ->and($f->projectIds)->toBe([5, 7])
        ->and($f->clientIds)->toBe([])
        ->and($f->billable)->toBeFalse()
        ->and($f->compare)->toBeTrue();
});

it('navega al periodo anterior y siguiente y da el de comparación', function () {
    $month = ReportFilters::fromQuery(['periodo' => 'mes', 'fecha' => '2026-03-31'], $this->today);
    expect($month->shifted(-1)->from->toDateString())->toBe('2026-02-01')
        ->and($month->shifted(-1)->to->toDateString())->toBe('2026-02-28')
        ->and($month->shifted(1)->from->toDateString())->toBe('2026-04-01');

    $week = ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => '2026-09-25'], $this->today);
    expect($week->comparison()->from->toDateString())->toBe('2026-09-14');

    $range = ReportFilters::fromQuery(['periodo' => 'rango', 'desde' => '2026-09-10', 'hasta' => '2026-09-19'], $this->today);
    expect($range->comparison()->from->toDateString())->toBe('2026-08-31')
        ->and($range->comparison()->to->toDateString())->toBe('2026-09-09');
});

it('vuelve a la URL con los mismos filtros (ida y vuelta)', function () {
    $query = ['periodo' => 'semana', 'fecha' => '2026-09-21', 'comparar' => '1', 'persona' => [2, 4], 'bolsa' => [9], 'facturable' => 'si'];
    $f = ReportFilters::fromQuery($query, $this->today);

    expect($f->toQuery())->toBe($query)
        ->and(ReportFilters::fromQuery($f->toQuery(), $this->today))->toEqual($f)
        ->and($f->cacheKey())->toBe(ReportFilters::fromQuery($query, $this->today)->cacheKey());
});
