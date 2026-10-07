<?php

use App\Domain\People\WorkdayCalculator;
use App\Domain\Time\Capacity;
use App\Enums\ClockEventKind;
use App\Models\Absence;
use App\Models\EmploymentProfile;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/*
| El diario del registro (PLAN-FASE-11 §7; D-337 y D-338): lo trabajado, la jornada teórica, la
| diferencia y las incidencias, también con tiempo parcial, verano, medianoche y cambio de hora.
*/

beforeEach(function () {
    $this->user = userWithRole('employee');
    // El registro empieza el 1 de septiembre (antes, nada cuenta: se llevaba en Woffu).
    Setting::set('people_register_starts_on', '2026-09-01');
});

/** El diario de un día, mirado desde «ahora» (por defecto, el día siguiente a mediodía). */
function diaryDay(string $date, ?string $now = null): array
{
    $at = $now === null ? madrid($date)->addDay()->setTime(12, 0) : madrid($now);

    return app(WorkdayCalculator::class)->forUser(test()->user, $date, $date, null, $at)[$date];
}

it('una jornada con la comida: 8 h trabajadas, 1 h de pausa y la diferencia a cero', function () {
    workday($this->user, '2026-10-05', '09:00', '18:00', '14:00', '15:00');

    $day = diaryDay('2026-10-05');

    expect($day['worked_minutes'])->toBe(480)
        ->and($day['pause_minutes'])->toBe(60)
        ->and($day['expected_minutes'])->toBe(480)
        ->and($day['difference_minutes'])->toBe(0)
        ->and($day['excess_minutes'])->toBe(0)
        ->and($day['incidents'])->toBe([])
        ->and($day['status'])->toBe('ok')
        ->and($day['events'])->toHaveCount(4)
        ->and($day['workdays'][0]['segments'])->toHaveCount(3);
});

it('el exceso se registra entero: 9 h trabajadas son 60 minutos de exceso', function () {
    workday($this->user, '2026-10-05', '08:00', '18:00', '14:00', '15:00');

    $day = diaryDay('2026-10-05');

    expect($day['worked_minutes'])->toBe(540)
        ->and($day['difference_minutes'])->toBe(60)
        ->and($day['excess_minutes'])->toBe(60)
        ->and($day['incidents'])->toBe([]);
});

it('cuenta los segundos reales y redondea al minuto', function () {
    punchAt($this->user, '2026-10-05 09:00:20', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-05 17:00:50', ClockEventKind::ClockOut);

    expect(diaryDay('2026-10-05')['worked_minutes'])->toBe(481);
});

it('tiempo parcial: la teórica es la de su jornada y por debajo de 30 minutos no es incidencia', function () {
    WorkSchedule::factory()->for($this->user)->create(['mon_minutes' => 360, 'tue_minutes' => 360, 'wed_minutes' => 360, 'thu_minutes' => 360, 'fri_minutes' => 360]);
    workday($this->user, '2026-10-05', '09:00', '15:00');
    workday($this->user, '2026-10-06', '09:00', '14:40');
    workday($this->user, '2026-10-07', '09:00', '14:15');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-07', null, madrid('2026-10-08 12:00'));

    expect($days['2026-10-05']['expected_minutes'])->toBe(360)
        ->and($days['2026-10-05']['difference_minutes'])->toBe(0)
        ->and($days['2026-10-06']['difference_minutes'])->toBe(-20)
        ->and($days['2026-10-06']['incidents'])->toBe([])
        ->and($days['2026-10-07']['difference_minutes'])->toBe(-45)
        ->and($days['2026-10-07']['incidents'])->toBe(['short_day'])
        ->and(WorkdayCalculator::totals($days)['difference_minutes'])->toBe(-65);
});

it('temporada de verano: 7 h del 1/7 al 31/8 con su pausa prevista, y la jornada normal fuera', function () {
    Setting::set('people_register_starts_on', '2026-06-01');
    WorkSchedule::factory()->for($this->user)->create([
        'expected_pause_minutes' => 60,
        'summer_starts_on' => '07-01',
        'summer_ends_on' => '08-31',
        'summer_week' => [420, 420, 420, 420, 420, 0, 0],
        'summer_expected_pause_minutes' => 0,
    ]);

    workday($this->user, '2026-06-30', '09:00', '18:00', '14:00', '15:00');
    workday($this->user, '2026-07-01', '08:00', '15:00');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-06-30', '2026-09-01', null, madrid('2026-09-02 12:00'));

    expect($days['2026-06-30']['expected_minutes'])->toBe(480)
        ->and($days['2026-06-30']['schedule']['expected_pause_minutes'])->toBe(60)
        ->and($days['2026-07-01']['expected_minutes'])->toBe(420)
        ->and($days['2026-07-01']['schedule']['expected_pause_minutes'])->toBe(0)
        ->and($days['2026-07-01']['difference_minutes'])->toBe(0)
        ->and($days['2026-08-31']['expected_minutes'])->toBe(420)
        ->and($days['2026-09-01']['expected_minutes'])->toBe(480);

    // Toda la app usa la misma jornada de verano (Capacity): la carga, los informes y la previsión.
    $capacity = app(Capacity::class)->forRange($this->user, CarbonImmutable::parse('2026-06-29'), CarbonImmutable::parse('2026-07-05'));
    expect(array_values($capacity))->toBe([480, 480, 420, 420, 420, 0, 0])
        ->and(app(Capacity::class)->plansForRanges([['user_id' => $this->user->id, 'from' => CarbonImmutable::parse('2026-06-01'), 'to' => CarbonImmutable::parse('2026-09-30')]])[0]->total())
        ->toBe(array_sum(app(Capacity::class)->forRange($this->user, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-09-30'))));
});

it('una temporada que cruza el fin de año (del 15/12 al 15/01)', function () {
    $schedule = WorkSchedule::factory()->for($this->user)->create([
        'summer_starts_on' => '12-15',
        'summer_ends_on' => '01-15',
        'summer_week' => [300, 300, 300, 300, 300, 0, 0],
    ]);

    expect($schedule->inSummer('2026-12-20'))->toBeTrue()
        ->and($schedule->inSummer('2027-01-10'))->toBeTrue()
        ->and($schedule->inSummer('2027-01-16'))->toBeFalse()
        ->and(app(Capacity::class)->onDate($this->user, CarbonImmutable::parse('2027-01-11')))->toBe(300)
        ->and(app(Capacity::class)->onDate($this->user, CarbonImmutable::parse('2027-01-18')))->toBe(480);
});

it('una jornada que cruza la medianoche es del día en que empieza', function () {
    punchAt($this->user, '2026-10-05 22:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-06 02:00', ClockEventKind::ClockOut);

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-06', null, madrid('2026-10-07 12:00'));

    expect($days['2026-10-05']['worked_minutes'])->toBe(240)
        ->and($days['2026-10-05']['incidents'])->toBe(['short_day'])
        ->and($days['2026-10-06']['worked_minutes'])->toBe(0)
        ->and($days['2026-10-06']['workdays'])->toBe([])
        ->and($days['2026-10-06']['incidents'])->toBe(['no_records']);
});

it('cambio de hora de octubre: de 22:00 a 04:00 son 7 h reales', function () {
    Holiday::factory()->create(['date' => '2026-10-24']);
    punchAt($this->user, '2026-10-24 22:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-25 04:00', ClockEventKind::ClockOut);

    expect(diaryDay('2026-10-24')['worked_minutes'])->toBe(7 * 60);
});

it('cambio de hora de marzo: de 22:00 a 04:00 son 5 h reales', function () {
    Setting::set('people_register_starts_on', '2026-03-01');
    punchAt($this->user, '2026-03-28 22:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-03-29 04:00', ClockEventKind::ClockOut);

    expect(diaryDay('2026-03-28')['worked_minutes'])->toBe(5 * 60);
});

it('festivo y fin de semana: sin jornada teórica; lo trabajado es exceso', function () {
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional']);
    workday($this->user, '2026-10-12', '10:00', '12:00');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-10', '2026-10-12', null, madrid('2026-10-13 12:00'));

    expect($days['2026-10-12']['expected_minutes'])->toBe(0)
        ->and($days['2026-10-12']['holiday'])->toBe('Fiesta Nacional')
        ->and($days['2026-10-12']['excess_minutes'])->toBe(120)
        ->and($days['2026-10-10']['status'])->toBe('off')
        ->and($days['2026-10-10']['incidents'])->toBe([]);
});

it('ausencias: la de día completo deja la teórica a cero y fichar ese día es incidencia; la parcial descuenta', function () {
    Absence::factory()->approved()->for($this->user)->between('2026-10-05', '2026-10-05')->create();
    Absence::factory()->approved()->for($this->user)->between('2026-10-06', '2026-10-06')->partial(240)->create();
    workday($this->user, '2026-10-05', '09:00', '11:00');
    workday($this->user, '2026-10-06', '09:00', '13:00');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-06', null, madrid('2026-10-07 12:00'));

    expect($days['2026-10-05']['expected_minutes'])->toBe(0)
        ->and($days['2026-10-05']['incidents'])->toBe(['during_absence'])
        ->and($days['2026-10-06']['expected_minutes'])->toBe(240)
        ->and($days['2026-10-06']['difference_minutes'])->toBe(0)
        ->and($days['2026-10-06']['incidents'])->toBe([]);
});

it('fuera del periodo de alta no hay jornada teórica ni incidencias', function () {
    EmploymentProfile::query()->create(['user_id' => $this->user->id, 'hire_date' => '2026-10-06', 'termination_date' => '2026-10-07']);
    workday($this->user, '2026-10-06', '09:00', '17:00');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-08', null, madrid('2026-10-09 12:00'));

    expect($days['2026-10-05']['expected_minutes'])->toBe(0)
        ->and($days['2026-10-05']['incidents'])->toBe([])
        ->and($days['2026-10-06']['expected_minutes'])->toBe(480)
        ->and($days['2026-10-07']['incidents'])->toBe(['no_records'])
        ->and($days['2026-10-08']['employed'])->toBeFalse()
        ->and($days['2026-10-08']['incidents'])->toBe([]);
});

it('antes del inicio del registro no hay teórica ni incidencias', function () {
    Setting::set('people_register_starts_on', '2026-10-06');

    $day = diaryDay('2026-10-05');

    expect($day['registered'])->toBeFalse()
        ->and($day['expected_minutes'])->toBe(0)
        ->and($day['incidents'])->toBe([]);
});

it('incidencias de los límites legales: descanso de menos de 12 h, más de 6 h seguidas y más de 9 h', function () {
    workday($this->user, '2026-10-05', '12:00', '23:00', '15:00', '15:30');
    workday($this->user, '2026-10-06', '09:00', '15:30');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-06', null, madrid('2026-10-07 12:00'));

    expect($days['2026-10-05']['incidents'])->toBe(['long_stretch', 'over_nine_hours'])
        ->and($days['2026-10-05']['status'])->toBe('warning')
        ->and($days['2026-10-06']['incidents'])->toBe(['short_day', 'short_rest', 'long_stretch'])
        ->and($days['2026-10-06']['incidents'])->not->toContain('over_nine_hours');
});

it('falta la salida: el tramo abierto no cuenta y el día pide una corrección', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    $day = diaryDay('2026-10-05');

    expect($day['incidents'])->toBe(['missing_clock_out'])
        ->and($day['worked_minutes'])->toBe(0)
        ->and($day['status'])->toBe('incident')
        ->and($day['workdays'][0]['open'])->toBeTrue()
        ->and($day['workdays'][0]['stale'])->toBeTrue();
});

it('salida fichada en la pausa: la pausa acaba con la salida y queda la incidencia', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);
    punchAt($this->user, '2026-10-05 14:00', ClockEventKind::PauseStart);
    punchAt($this->user, '2026-10-05 14:30', ClockEventKind::ClockOut);

    $day = diaryDay('2026-10-05');

    expect($day['worked_minutes'])->toBe(300)
        ->and($day['pause_minutes'])->toBe(30)
        ->and($day['incidents'])->toContain('pause_open');
});

it('sin fichajes en un día con jornada', function () {
    workday($this->user, '2026-10-05', '09:00', '17:00');

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-06', null, madrid('2026-10-07 12:00'));

    expect($days['2026-10-06']['incidents'])->toBe(['no_records'])
        ->and($days['2026-10-06']['status'])->toBe('incident')
        ->and($days['2026-10-06']['difference_minutes'])->toBe(-480);
});

it('hoy en curso: cuenta hasta ahora, sin incidencias de cierre y con los días futuros fuera de los totales', function () {
    punchAt($this->user, '2026-10-05 09:00', ClockEventKind::ClockIn);

    $days = app(WorkdayCalculator::class)->forUser($this->user, '2026-10-05', '2026-10-07', null, madrid('2026-10-05 11:30'));

    expect($days['2026-10-05']['in_progress'])->toBeTrue()
        ->and($days['2026-10-05']['worked_minutes'])->toBe(150)
        ->and($days['2026-10-05']['incidents'])->toBe([])
        ->and($days['2026-10-05']['status'])->toBe('in_progress')
        ->and($days['2026-10-06']['status'])->toBe('future')
        ->and($days['2026-10-06']['difference_minutes'])->toBeNull()
        // Sin cerrar, hoy no tiene diferencia ni suma su teórica en los totales (no hay deuda a media mañana).
        ->and($days['2026-10-05']['difference_minutes'])->toBeNull()
        ->and(WorkdayCalculator::totals($days)['expected_minutes'])->toBe(0)
        ->and(WorkdayCalculator::totals($days)['worked_minutes'])->toBe(150);
});

it('una jornada partida se suma en el día', function () {
    workday($this->user, '2026-10-05', '08:00', '12:00');
    workday($this->user, '2026-10-05', '16:00', '20:00');

    $day = diaryDay('2026-10-05');

    expect($day['worked_minutes'])->toBe(480)
        ->and($day['workdays'])->toHaveCount(2)
        ->and($day['incidents'])->toBe([]);
});
