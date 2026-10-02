<?php

use App\Domain\Recurring\RecurrenceDescriber;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Fechas de las tareas recurrentes (D-059): RecurringTaskRule::occurrencesBetween() con los mismos
| casos que la vista previa del formulario (tests/fixtures/recurrence-cases.json, que también usa
| tests/js/recurring-dates.test.ts), fechas acotadas de 2000 a 2100 y sin recorrer la serie desde
| su inicio (una regla del año 1 cuesta lo mismo que una de hoy).
*/

/** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: list<string>}> */
function sharedRecurrenceCases(): array
{
    /** @var array{cases: list<array{name: string, rule: array<string, mixed>, from: string, to: string, expected: list<string>}>} $fixture */
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/recurrence-cases.json'), true);
    $cases = [];

    foreach ($fixture['cases'] as $case) {
        $cases[$case['name']] = [$case['rule'], $case['from'], $case['to'], $case['expected']];
    }

    return $cases;
}

it('calcula las fechas igual que la vista previa del formulario', function (array $attributes, string $from, string $to, array $expected) {
    $rule = new RecurringTaskRule($attributes);

    expect($rule->occurrencesBetween(CarbonImmutable::parse($from), CarbonImmutable::parse($to)))->toBe($expected);
})->with(sharedRecurrenceCases());

it('una regla que empezó hace siglos no se recorre desde su inicio', function () {
    $ancient = new RecurringTaskRule(['frequency' => 'weekly', 'interval' => 1, 'weekday' => 3, 'starts_on' => '0001-01-01', 'is_active' => true]);
    $describer = app(RecurrenceDescriber::class);
    $started = microtime(true);

    foreach (range(1, 20) as $i) {
        $ancient->occurrencesBetween(CarbonImmutable::parse('2100-12-01'), CarbonImmutable::parse('2100-12-31'));
        $describer->nextDate($ancient, CarbonImmutable::parse('2026-10-05'));
    }

    // Recorriendo las 110.000 semanas desde el año 1, cada llamada tardaba más de un segundo.
    expect(microtime(true) - $started)->toBeLessThan(1.0)
        ->and($ancient->occurrencesBetween(CarbonImmutable::parse('2100-12-01'), CarbonImmutable::parse('2100-12-31')))
        ->toBe(['2100-12-01', '2100-12-08', '2100-12-15', '2100-12-22', '2100-12-29'])
        ->and($describer->nextDate($ancient, CarbonImmutable::parse('2026-10-05')))->toBe('2026-10-07');
});

describe('desde y hasta, entre 2000 y 2100', function () {
    beforeEach(function () {
        TaskStatus::ensureDefaults();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'Europe/Madrid'));
        $owner = User::factory()->employee()->create();
        $this->project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAs($owner);
        $this->post = fn (array $dates) => $this->post("/proyectos/{$this->project->id}/tareas-recurrentes", [
            'title' => 'Informe', 'priority' => 'normal', 'frequency' => 'weekly', 'interval' => 1, 'weekday' => 3,
            'due_offset_days' => 0, 'ends_on' => null, 'is_active' => true, ...$dates,
        ]);
    });

    it('rechaza fechas fuera del rango con su mensaje', function (array $dates, string $field) {
        ($this->post)($dates)->assertSessionHasErrors([$field => 'Elige una fecha entre el 01/01/2000 y el 31/12/2100.']);

        expect(RecurringTaskRule::query()->count())->toBe(0);
    })->with([
        'desde el año 1' => [['starts_on' => '0001-01-01'], 'starts_on'],
        'desde 1999' => [['starts_on' => '1999-12-31'], 'starts_on'],
        'desde 2101' => [['starts_on' => '2101-01-01'], 'starts_on'],
        'hasta 2101' => [['starts_on' => '2026-10-05', 'ends_on' => '2101-01-01'], 'ends_on'],
    ]);

    it('admite los extremos del rango', function () {
        ($this->post)(['starts_on' => '2000-01-01', 'ends_on' => '2100-12-31'])->assertSessionHasNoErrors();

        $rule = RecurringTaskRule::query()->sole();
        expect($rule->starts_on->toDateString())->toBe('2000-01-01')
            ->and($rule->ends_on?->toDateString())->toBe('2100-12-31');
    });
});
