<?php

use App\Domain\Reports\Delivery\ScheduleClock;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use Carbon\CarbonImmutable;

/*
| Próximo envío de una programación (D-141): reglas en hora de Madrid, resultado en UTC, también
| alrededor de los cambios de hora de 2026 (29 de marzo, +1 h; 25 de octubre, -1 h).
*/

function nextRun(ScheduleFrequency $frequency, string $time, ?int $weekday, ?int $monthDay, string $afterUtc, ?string $runAtUtc = null): ?string
{
    return app(ScheduleClock::class)
        ->next($frequency, $time, $weekday, $monthDay, $runAtUtc === null ? null : CarbonImmutable::parse($runAtUtc, 'UTC'), CarbonImmutable::parse($afterUtc, 'UTC'))
        ?->toIso8601ZuluString();
}

it('semanal: el siguiente día de la semana a esa hora de Madrid, antes y después del cambio de marzo', function () {
    // Lunes a las 08:00: en invierno es 07:00 UTC; en verano, 06:00 UTC.
    expect(nextRun(ScheduleFrequency::Weekly, '08:00', 1, null, '2026-03-20 12:00'))->toBe('2026-03-23T07:00:00Z')
        ->and(nextRun(ScheduleFrequency::Weekly, '08:00', 1, null, '2026-03-27 12:00'))->toBe('2026-03-30T06:00:00Z')
        // El mismo día, antes de la hora: hoy; justo a la hora: la semana siguiente.
        ->and(nextRun(ScheduleFrequency::Weekly, '08:00', 1, null, '2026-03-30 05:59'))->toBe('2026-03-30T06:00:00Z')
        ->and(nextRun(ScheduleFrequency::Weekly, '08:00', 1, null, '2026-03-30 06:00'))->toBe('2026-04-06T06:00:00Z')
        // Domingo: día 7.
        ->and(nextRun(ScheduleFrequency::Weekly, '20:30', 7, null, '2026-10-21 10:00'))->toBe('2026-10-25T19:30:00Z');
});

it('semanal: una hora que no existe el día del cambio pasa al reloj ya adelantado', function () {
    // Domingo 29/03/2026 a las 02:30 no existe en Madrid: pasa a las 03:30 CEST (01:30 UTC).
    expect(nextRun(ScheduleFrequency::Weekly, '02:30', 7, null, '2026-03-28 12:00'))->toBe('2026-03-29T01:30:00Z')
        // La semana siguiente vuelve a ser a las 02:30 (00:30 UTC).
        ->and(nextRun(ScheduleFrequency::Weekly, '02:30', 7, null, '2026-03-29 02:00'))->toBe('2026-04-05T00:30:00Z');
});

it('mensual: el día del mes a esa hora, con el cambio de octubre', function () {
    // Día 1 a las 08:00: el 1 de octubre aún es verano (06:00 UTC); el 1 de noviembre, invierno (07:00 UTC).
    expect(nextRun(ScheduleFrequency::Monthly, '08:00', null, 1, '2026-09-15 10:00'))->toBe('2026-10-01T06:00:00Z')
        ->and(nextRun(ScheduleFrequency::Monthly, '08:00', null, 1, '2026-10-01 06:00'))->toBe('2026-11-01T07:00:00Z')
        ->and(nextRun(ScheduleFrequency::Monthly, '08:00', null, 15, '2026-10-20 10:00'))->toBe('2026-11-15T07:00:00Z');
});

it('mensual: el último día de cada mes (0)', function () {
    expect(nextRun(ScheduleFrequency::Monthly, '18:00', null, 0, '2026-02-10 10:00'))->toBe('2026-02-28T17:00:00Z')
        ->and(nextRun(ScheduleFrequency::Monthly, '18:00', null, 0, '2026-02-28 17:00'))->toBe('2026-03-31T16:00:00Z')
        ->and(nextRun(ScheduleFrequency::Monthly, '18:00', null, 0, '2028-02-01 10:00'))->toBe('2028-02-29T17:00:00Z');
});

it('una vez: su fecha y hora mientras no haya pasado', function () {
    $runAt = ScheduleClock::localInstant('2026-10-25', '10:00');

    // El 25/10/2026 ya es invierno: las 10:00 de Madrid son las 09:00 UTC.
    expect($runAt->toIso8601ZuluString())->toBe('2026-10-25T09:00:00Z')
        ->and(ScheduleClock::localInstant('2026-07-01', '10:00')->toIso8601ZuluString())->toBe('2026-07-01T08:00:00Z')
        ->and(nextRun(ScheduleFrequency::Once, '10:00', null, null, '2026-10-20 10:00', '2026-10-25 09:00'))->toBe('2026-10-25T09:00:00Z')
        ->and(nextRun(ScheduleFrequency::Once, '10:00', null, null, '2026-10-25 09:00', '2026-10-25 09:00'))->toBeNull();
});
