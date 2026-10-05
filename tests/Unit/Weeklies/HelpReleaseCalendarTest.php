<?php

use App\Domain\Weeklies\Help\HelpReleaseCalendar;
use Carbon\CarbonImmutable;

/*
| Las novedades automáticas del centro de ayuda (F-150 y F-152), port de helpCenter.ts de
| WeeklySync: la versión de una fecha, su fecha de publicación, «en curso» y los estados del listado.
*/

it('da la versión de una fecha: serie desde 2026, mes y semana del mes (como mucho 5)', function (string $date, array $expected) {
    expect(HelpReleaseCalendar::versionFor(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'primer día' => ['2026-10-01', ['major_version' => 1, 'month_number' => 10, 'week_of_month' => 1]],
    'día 7' => ['2026-10-07', ['major_version' => 1, 'month_number' => 10, 'week_of_month' => 1]],
    'día 8' => ['2026-10-08', ['major_version' => 1, 'month_number' => 10, 'week_of_month' => 2]],
    'día 29' => ['2026-10-29', ['major_version' => 1, 'month_number' => 10, 'week_of_month' => 5]],
    'día 31' => ['2026-10-31', ['major_version' => 1, 'month_number' => 10, 'week_of_month' => 5]],
    '2027' => ['2027-01-15', ['major_version' => 2, 'month_number' => 1, 'week_of_month' => 3]],
    'antes de 2026, serie 1' => ['2025-12-31', ['major_version' => 1, 'month_number' => 12, 'week_of_month' => 5]],
]);

it('la etiqueta y la fecha de publicación (día (semana − 1) · 7 + 5, acotado al mes)', function () {
    expect(HelpReleaseCalendar::label(1, 10, 2))->toBe('V.1.10.2')
        ->and(HelpReleaseCalendar::publishedOn(1, 10, 1))->toBe('2026-10-05')
        ->and(HelpReleaseCalendar::publishedOn(1, 10, 5))->toBe('2026-10-31')
        ->and(HelpReleaseCalendar::publishedOn(1, 2, 5))->toBe('2026-02-28')
        ->and(HelpReleaseCalendar::publishedOn(2, 1, 1))->toBe('2027-01-05');
});

it('está en curso la de esta semana sin contenido propio hasta su fecha', function () {
    $default = 'Por defecto';
    $today = CarbonImmutable::parse('2026-10-03');

    expect(HelpReleaseCalendar::hasContent($default, 0, $default))->toBeFalse()
        ->and(HelpReleaseCalendar::hasContent('', 0, $default))->toBeFalse()
        ->and(HelpReleaseCalendar::hasContent('Otro resumen', 0, $default))->toBeTrue()
        ->and(HelpReleaseCalendar::hasContent($default, 2, $default))->toBeTrue()
        ->and(HelpReleaseCalendar::isInProgress(1, 10, 1, false, $today))->toBeTrue()
        // Con contenido ya no; ni otra semana; ni pasada su fecha (el día 6 de esa misma semana del mes).
        ->and(HelpReleaseCalendar::isInProgress(1, 10, 1, true, $today))->toBeFalse()
        ->and(HelpReleaseCalendar::isInProgress(1, 9, 5, false, $today))->toBeFalse()
        ->and(HelpReleaseCalendar::isInProgress(1, 10, 1, false, CarbonImmutable::parse('2026-10-06')))->toBeFalse();
});

it('ordena por fecha y marca la primera que no está en curso como nueva', function () {
    $entries = HelpReleaseCalendar::withStatuses([
        ['key' => 'release:1', 'published_on' => '2026-09-26', 'in_progress' => false],
        ['key' => 'release:2', 'published_on' => '2026-10-05', 'in_progress' => true],
        ['key' => 'update:1', 'published_on' => '2026-10-01', 'in_progress' => false],
        ['key' => 'release:3', 'published_on' => '2026-10-01', 'in_progress' => false],
    ]);

    expect(array_column($entries, 'key'))->toBe(['release:2', 'update:1', 'release:3', 'release:1'])
        ->and(array_column($entries, 'status'))->toBe(['in_progress', 'new', 'previous', 'previous']);
});
