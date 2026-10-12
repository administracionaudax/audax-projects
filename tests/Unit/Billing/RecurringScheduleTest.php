<?php

use App\Domain\Billing\Recurring\DynamicWords;
use App\Domain\Billing\Recurring\RecurringSchedule;
use App\Enums\RecurringPeriodicity;
use App\Models\RecurringInvoice;
use Carbon\CarbonImmutable;

/*
| El calendario de las recurrentes y sus palabras dinámicas (E3): periodicidades, fin de mes, años
| bisiestos, fin por fecha o por número de veces, y las palabras del periodo.
*/

function recurringPlan(array $attributes): RecurringInvoice
{
    $recurring = new RecurringInvoice;
    $recurring->forceFill([
        'periodicity' => RecurringPeriodicity::Monthly,
        'interval_months' => 1,
        'day_of_month' => 1,
        'start_date' => '2026-01-01',
        'occurrences_count' => 0,
        ...$attributes,
    ]);

    return $recurring;
}

/**
 * @return list<string>
 */
function datesOf(RecurringInvoice $recurring, int $count): array
{
    $recurring->forceFill(['next_run_on' => RecurringSchedule::first($recurring)?->toDateString()]);

    return array_column(RecurringSchedule::upcoming($recurring, $count), 'date');
}

it('mensual, bimestral, trimestral, semestral, anual y cada N meses desde el mes de inicio', function () {
    expect(datesOf(recurringPlan(['day_of_month' => 15, 'start_date' => '2026-10-15']), 3))->toBe(['2026-10-15', '2026-11-15', '2026-12-15'])
        ->and(datesOf(recurringPlan(['interval_months' => 2, 'day_of_month' => 1, 'start_date' => '2026-11-01']), 3))->toBe(['2026-11-01', '2027-01-01', '2027-03-01'])
        ->and(datesOf(recurringPlan(['interval_months' => 3, 'day_of_month' => 29, 'start_date' => '2026-10-01']), 4))->toBe(['2026-10-29', '2027-01-29', '2027-04-29', '2027-07-29'])
        ->and(datesOf(recurringPlan(['interval_months' => 6, 'day_of_month' => 10, 'start_date' => '2026-03-01']), 3))->toBe(['2026-03-10', '2026-09-10', '2027-03-10'])
        ->and(datesOf(recurringPlan(['interval_months' => 12, 'day_of_month' => 5, 'start_date' => '2026-01-05']), 3))->toBe(['2026-01-05', '2027-01-05', '2028-01-05'])
        ->and(datesOf(recurringPlan(['periodicity' => RecurringPeriodicity::Custom, 'interval_months' => 4, 'day_of_month' => 1, 'start_date' => '2026-01-01']), 3))->toBe(['2026-01-01', '2026-05-01', '2026-09-01']);

    expect(RecurringPeriodicity::fromMonths(3))->toBe(RecurringPeriodicity::Quarterly)
        ->and(RecurringPeriodicity::fromMonths(4))->toBe(RecurringPeriodicity::Custom);
});

it('si el día de inicio ya pasó en su mes, empieza en el siguiente periodo', function () {
    expect(datesOf(recurringPlan(['day_of_month' => 1, 'start_date' => '2026-10-15']), 2))->toBe(['2026-11-01', '2026-12-01']);
});

it('el 31, el 30 y el 29 caen en el último día de los meses cortos y vuelven a su día el mes siguiente', function () {
    expect(datesOf(recurringPlan(['day_of_month' => 31, 'start_date' => '2027-01-01']), 5))
        ->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']);
    expect(datesOf(recurringPlan(['day_of_month' => 30, 'start_date' => '2027-01-01']), 3))
        ->toBe(['2027-01-30', '2027-02-28', '2027-03-30']);
});

it('en un año bisiesto, febrero llega al 29', function () {
    expect(datesOf(recurringPlan(['day_of_month' => 29, 'start_date' => '2028-01-01']), 3))->toBe(['2028-01-29', '2028-02-29', '2028-03-29'])
        ->and(datesOf(recurringPlan(['day_of_month' => 31, 'start_date' => '2028-02-01']), 2))->toBe(['2028-02-29', '2028-03-31'])
        // Anual el 29 de febrero: los años no bisiestos, el 28.
        ->and(datesOf(recurringPlan(['interval_months' => 12, 'day_of_month' => 29, 'start_date' => '2028-02-01']), 3))->toBe(['2028-02-29', '2029-02-28', '2030-02-28']);
});

it('acaba en la fecha de fin o al llegar al número de veces; un periodo omitido no gasta una vez', function () {
    expect(datesOf(recurringPlan(['day_of_month' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-12-15']), 12))->toBe(['2026-10-01', '2026-11-01', '2026-12-01'])
        ->and(datesOf(recurringPlan(['day_of_month' => 1, 'start_date' => '2026-10-01', 'max_occurrences' => 2]), 12))->toBe(['2026-10-01', '2026-11-01']);

    $skipping = recurringPlan(['day_of_month' => 1, 'start_date' => '2026-10-01', 'max_occurrences' => 2, 'skipped_dates' => ['2026-11-01']]);
    $skipping->forceFill(['next_run_on' => '2026-10-01']);
    expect(RecurringSchedule::upcoming($skipping, 12))->toBe([
        ['date' => '2026-10-01', 'skipped' => false],
        ['date' => '2026-11-01', 'skipped' => true],
        ['date' => '2026-12-01', 'skipped' => false],
    ]);

    // Con las que ya generó, se acaba antes.
    $half = recurringPlan(['day_of_month' => 1, 'start_date' => '2026-10-01', 'max_occurrences' => 3, 'occurrences_count' => 2]);
    expect(RecurringSchedule::next($half, CarbonImmutable::parse('2026-11-01'), 3))->toBeNull()
        ->and(RecurringSchedule::next($half, CarbonImmutable::parse('2026-10-01'), 2)?->toDateString())->toBe('2026-11-01');
});

it('cambia las palabras dinámicas por las del periodo, en español y en inglés', function () {
    $october = CarbonImmutable::parse('2026-10-29');
    expect(DynamicWords::apply('Fee de [mes] de [año] · [Mes]', $october))->toBe('Fee de octubre de 2026 · Octubre')
        ->and(DynamicWords::apply('Horas de [mes anterior] ([trimestre], [fecha])', $october))->toBe('Horas de septiembre (4T, 29/10/2026)')
        ->and(DynamicWords::apply('[Mes siguiente] [año siguiente] [año anterior] [trimestre anterior]', $october))->toBe('Noviembre 2027 2025 3T')
        // Sin espacios, como en Holded, y una palabra desconocida se queda tal cual.
        ->and(DynamicWords::apply('[mesanterior] [AÑO] [sprint] [proyecto]', $october))->toBe('septiembre 2026 [sprint] [proyecto]')
        // En enero, el mes anterior es del año anterior.
        ->and(DynamicWords::apply('[mes anterior] de [año del mes anterior]', CarbonImmutable::parse('2027-01-29')))->toBe('diciembre de 2026')
        ->and(DynamicWords::apply('Fee [Mes] [año] · [trimestre]', $october, 'en'))->toBe('Fee October 2026 · Q4')
        ->and(DynamicWords::apply(null, $october))->toBeNull()
        ->and(DynamicWords::apply('Sin palabras', $october))->toBe('Sin palabras');
});
