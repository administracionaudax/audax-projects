<?php

use App\Domain\Weeklies\WeeklyCalendar;
use App\Domain\Weeklies\WeeklyPeriod;
use Carbon\CarbonImmutable;

/*
| Calendario de la weekly (D-150, F-070): lunes a viernes, plazo el viernes, número Wnn-aa y etiqueta
| de WeeklySync. El año del número es el año ISO de la semana (D-153).
*/

beforeEach(fn () => $this->calendar = new WeeklyCalendar);

it('da la semana de lunes a viernes de cualquier día, también del fin de semana', function (string $date, string $monday) {
    $period = $this->calendar->periodFor($date);

    expect($period->start->toDateString())->toBe($monday)
        ->and($period->end->toDateString())->toBe(CarbonImmutable::parse($monday)->addDays(4)->toDateString())
        ->and($period->deadline->toDateString())->toBe($period->end->toDateString())
        ->and($period->start->isMonday())->toBeTrue();
})->with([
    'lunes' => ['2026-10-05', '2026-10-05'],
    'miércoles' => ['2026-10-07', '2026-10-05'],
    'viernes' => ['2026-10-09', '2026-10-05'],
    'sábado' => ['2026-10-10', '2026-10-05'],
    'domingo' => ['2026-10-11', '2026-10-05'],
    'cambio de año' => ['2027-01-01', '2026-12-28'],
    'bisiesto' => ['2028-02-29', '2028-02-28'],
]);

it('numera y etiqueta como WeeklySync', function (string $date, string $number, string $label) {
    $period = $this->calendar->periodFor($date);

    expect($period->number)->toBe($number)
        ->and($period->label)->toBe($label);
})->with([
    ['2026-10-05', 'W41-26', 'Semana 41 (Lun 05/10 - Vie 09/10)'],
    // La primera semana importada de WeeklySync (23/03/2026).
    ['2026-03-23', 'W13-26', 'Semana 13 (Lun 23/03 - Vie 27/03)'],
    ['2026-01-02', 'W01-26', 'Semana 01 (Lun 29/12 - Vie 02/01)'],
    ['2025-12-31', 'W01-26', 'Semana 01 (Lun 29/12 - Vie 02/01)'],
    // Viernes en enero de una semana ISO del año anterior: año ISO (D-153), WeeklySync diría W53-27.
    ['2026-12-30', 'W53-26', 'Semana 53 (Lun 28/12 - Vie 01/01)'],
    ['2027-01-04', 'W01-27', 'Semana 01 (Lun 04/01 - Vie 08/01)'],
]);

it('interpreta los instantes en la hora de Madrid', function () {
    // Domingo 11/10 a las 23:30 UTC = lunes 12/10 a la 01:30 en Madrid.
    $period = $this->calendar->periodFor(CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC'));

    expect($period->start->toDateString())->toBe('2026-10-12');

    // Una fecha de un cast `date` (medianoche UTC) sigue siendo ese día.
    expect($this->calendar->periodFor(CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC'))->start->toDateString())->toBe('2026-10-05');
});

it('la semana en curso sale de la hora de Madrid', function () {
    expect($this->calendar->current(CarbonImmutable::parse('2026-10-04 22:30:00', 'UTC'))->number)->toBe('W41-26')
        ->and($this->calendar->current(CarbonImmutable::parse('2026-10-04 21:30:00', 'UTC'))->number)->toBe('W40-26');
});

it('la siguiente y la anterior están a siete días', function () {
    $period = $this->calendar->periodFor('2026-12-23');

    $next = $this->calendar->next($period);
    $previous = $this->calendar->previous('2026-12-23');

    expect($next)->toBeInstanceOf(WeeklyPeriod::class)
        ->and($next->start->toDateString())->toBe('2026-12-28')
        ->and($next->number)->toBe('W53-26')
        ->and($this->calendar->next($next)->number)->toBe('W01-27')
        ->and($previous->start->toDateString())->toBe('2026-12-14');
});

it('cada semana de varios años tiene un número distinto', function () {
    $numbers = [];
    $period = $this->calendar->periodFor('2025-12-29');

    for ($i = 0; $i < 52 * 6; $i++) {
        $numbers[] = $period->number;
        $period = $this->calendar->next($period);
    }

    expect(array_unique($numbers))->toHaveCount(count($numbers));
});

it('da los atributos para crear la semana', function () {
    expect($this->calendar->periodFor('2026-10-07')->toAttributes())->toBe([
        'number' => 'W41-26',
        'label' => 'Semana 41 (Lun 05/10 - Vie 09/10)',
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-09',
        'deadline_date' => '2026-10-09',
    ]);
});
