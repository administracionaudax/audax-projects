<?php

use App\Domain\Weeklies\Reminders\WeeklyReminderSchedule;
use App\Domain\Weeklies\Reminders\WeeklyTemplates;
use App\Enums\WeeklyReminderChannel;
use App\Models\WeeklyReminderRule;
use Carbon\CarbonImmutable;

/*
| Qué reglas tocan ahora (10.5, F-101, F-102 y F-107; port de findDueReminders de WeeklySync con
| instantes reales): margen de 10 minutos, días ISO y hora de Madrid, la medianoche y los dos
| cambios de hora. Y el render de las plantillas con los casos compartidos con TypeScript.
*/

function reminderRuleAt(int $id, int $day, string $time, bool $enabled = true): WeeklyReminderRule
{
    $rule = new WeeklyReminderRule(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => $day, 'time' => $time, 'enabled' => $enabled]);
    $rule->id = $id;

    return $rule;
}

function reminderMadrid(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Madrid');
}

/**
 * @param  list<WeeklyReminderRule>  $rules
 * @return list<string> «id@fecha»
 */
function remindersDueAt(array $rules, string $local, int $lookback = 10): array
{
    return array_map(
        fn (array $due): string => "{$due['rule']->id}@{$due['date']}",
        WeeklyReminderSchedule::due($rules, reminderMadrid($local), $lookback),
    );
}

it('toca una regla cuando su hora de Madrid cae en los últimos 10 minutos, en dos pasadas como mucho', function () {
    // Viernes 09/10/2026 a las 16:00 de Madrid (14:00 UTC).
    $rules = [reminderRuleAt(1, 5, '16:00'), reminderRuleAt(2, 5, '16:20'), reminderRuleAt(3, 4, '16:00'), reminderRuleAt(4, 5, '16:00', enabled: false)];

    expect(remindersDueAt($rules, '2026-10-09 15:59'))->toBe([])
        ->and(remindersDueAt($rules, '2026-10-09 16:00'))->toBe(['1@2026-10-09'])
        ->and(remindersDueAt($rules, '2026-10-09 16:05'))->toBe(['1@2026-10-09'])
        ->and(remindersDueAt($rules, '2026-10-09 16:09'))->toBe(['1@2026-10-09'])
        ->and(remindersDueAt($rules, '2026-10-09 16:10'))->toBe([])
        ->and(remindersDueAt($rules, '2026-10-08 16:03'))->toBe(['3@2026-10-08']);

    // El instante es UTC: las 16:00 de Madrid en octubre son las 14:00 UTC.
    $due = WeeklyReminderSchedule::due($rules, reminderMadrid('2026-10-09 16:02'));
    expect($due[0]['due']->toIso8601ZuluString())->toBe('2026-10-09T14:00:00Z');
});

it('mira también ayer si el margen cruza la medianoche, con el día de la regla', function () {
    $rules = [reminderRuleAt(1, 7, '23:58'), reminderRuleAt(2, 1, '00:02')];

    // Lunes 12/10 a las 00:03: la del domingo a las 23:58 y la del lunes a las 00:02.
    expect(remindersDueAt($rules, '2026-10-12 00:03'))->toBe(['1@2026-10-11', '2@2026-10-12']);
});

it('al pasar a la hora de verano, una hora que no existe se dispara al saltar y no se pierde', function () {
    // Domingo 29/03/2026: de las 02:00 se pasa a las 03:00. Una regla a las 02:30 sale a las 03:30.
    $rules = [reminderRuleAt(1, 7, '02:30'), reminderRuleAt(2, 7, '03:05')];

    expect(remindersDueAt($rules, '2026-03-29 03:00'))->toBe([])
        ->and(remindersDueAt($rules, '2026-03-29 03:05'))->toBe(['2@2026-03-29'])
        ->and(remindersDueAt($rules, '2026-03-29 03:30'))->toBe(['1@2026-03-29'])
        ->and(remindersDueAt($rules, '2026-03-29 03:35'))->toBe(['1@2026-03-29']);
});

it('al volver a la hora de invierno, una hora que se repite se dispara una sola vez (la segunda, ya en invierno)', function () {
    // Domingo 25/10/2026: de las 03:00 de verano se vuelve a las 02:00. Las 02:30 existen dos veces.
    $rules = [reminderRuleAt(1, 7, '02:30')];
    $first = CarbonImmutable::parse('2026-10-25T00:30:00Z'); // 02:30 CEST
    $second = CarbonImmutable::parse('2026-10-25T01:30:00Z'); // 02:30 CET

    $atFirst = WeeklyReminderSchedule::due($rules, $first->addMinutes(2));
    $atSecond = WeeklyReminderSchedule::due($rules, $second->addMinutes(2));

    expect($atFirst)->toBe([])
        ->and($atSecond)->toHaveCount(1)
        ->and($atSecond[0]['due']->toIso8601ZuluString())->toBe('2026-10-25T01:30:00Z')
        // La misma clave del disparo en las dos: aunque tocara dos veces, el registro lo evita.
        ->and(WeeklyReminderSchedule::triggerKey($rules[0], 12, '2026-10-25'))->toBe('rule:1:12:2026-10-25T02:30');
});

it('ignora las horas no válidas', function () {
    expect(WeeklyReminderSchedule::minuteOfDay('16:00'))->toBe(960)
        ->and(WeeklyReminderSchedule::minuteOfDay('00:00'))->toBe(0)
        ->and(WeeklyReminderSchedule::minuteOfDay('23:59'))->toBe(1439)
        ->and(WeeklyReminderSchedule::minuteOfDay('24:00'))->toBeNull()
        ->and(WeeklyReminderSchedule::minuteOfDay('9:00'))->toBeNull()
        ->and(WeeklyReminderSchedule::minuteOfDay('16:60'))->toBeNull()
        ->and(remindersDueAt([reminderRuleAt(1, 5, '25:00')], '2026-10-09 16:00'))->toBe([]);
});

it('las plantillas sustituyen sus variables como el gemelo de TypeScript (casos compartidos)', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/weeklies/template-render.json')), true);

    foreach ($fixture['cases'] as $case) {
        expect(WeeklyTemplates::render($case['text'], $fixture['values']))->toBe($case['expected']);
    }

    expect(WeeklyTemplates::normalize("Hola\r\nadiós  \n\n"))->toBe("Hola\nadiós");
});
