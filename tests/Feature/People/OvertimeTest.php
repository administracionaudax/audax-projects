<?php

use App\Domain\Notifications\NotificationCatalog;
use App\Domain\People\ClockCorrectionService;
use App\Domain\People\MonthCloser;
use App\Domain\People\OvertimeService;
use App\Domain\People\RegisterIntegrity;
use App\Domain\People\TimeBalanceLedger;
use App\Enums\BalanceMovementKind;
use App\Enums\HourType;
use App\Enums\OvertimeDestination;
use App\Models\EmploymentProfile;
use App\Models\OvertimeDecision;
use App\Models\Setting;
use App\Models\TimeBalanceMovement;
use App\Notifications\People\OvertimeCapReached;
use App\Notifications\People\OvertimeWeeklySummary;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Horas extra y saldo de horas (PLAN-FASE-11 §7.2; P6: «sí hay horas extra y se fichan»; D-349 y
| D-350): el responsable o RR. HH. clasifica el exceso del día (extra o flexibilidad) con su destino;
| lo compensado pasa al saldo a 80 minutos por hora; tope de 80 h al año con aviso; resumen
| semanal. Nadie decide lo suyo. Todo es de solo alta.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee);
    $this->hr = tap(userWithRole('employee'), fn ($user) => $user->givePermissionTo('manage-people'));
    $this->overtime = app(OvertimeService::class);
    $this->ledger = app(TimeBalanceLedger::class);

    // Lunes 5: 9:00–20:00 con 1 h de comida = 10 h, 2 h de exceso sobre 8 h.
    workday($this->employee, '2026-10-05', '09:00', '20:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-06 10:00'));
});

it('el responsable clasifica el exceso: horas extra a compensar (80 minutos por hora al saldo) y el resto flexibilidad', function () {
    $decision = $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 90, OvertimeDestination::Compensate, 'Entrega de la campaña.');

    expect($decision->hour_type)->toBe(HourType::Overtime)
        ->and($decision->excess_minutes)->toBe(120)
        ->and($decision->overtime_minutes)->toBe(90)
        ->and($decision->flex_minutes)->toBe(30)
        ->and($this->ledger->balance($this->employee->id))->toBe(120)
        ->and(TimeBalanceMovement::query()->sole()->kind)->toBe(BalanceMovementKind::Overtime)
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();

    $pending = $this->ledger->pendingCompensation($this->employee->id);
    expect($pending)->toHaveCount(1)
        ->and($pending[0]['deadline'])->toBe('2027-02-05')
        ->and($pending[0]['expired'])->toBeFalse();
});

it('nadie clasifica lo suyo; ni un compañero ni otro responsable', function () {
    $colleague = userWithRole('employee', ['department_id' => $this->employee->department_id]);
    ['manager' => $other] = peopleTeam();

    foreach ([$this->employee, $colleague, $other] as $actor) {
        expect(fn () => $this->overtime->decide($actor, $this->employee, '2026-10-05', 60, OvertimeDestination::Pay))->toThrow(AuthorizationException::class);
    }

    // RR. HH. sí, también el de un responsable (que no puede decidir lo suyo).
    workday($this->manager, '2026-10-02', '08:00', '19:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-06 10:00'));
    expect(fn () => $this->overtime->decide($this->manager, $this->manager, '2026-10-02', 60, OvertimeDestination::Pay))->toThrow(AuthorizationException::class);
    expect($this->overtime->decide($this->hr, $this->manager, '2026-10-02', 60, OvertimeDestination::Pay)->overtime_minutes)->toBe(60);
});

it('valida el exceso, el destino y que el día ya esté cerrado', function () {
    expect(fn () => $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 121, OvertimeDestination::Pay))->toThrow(ValidationException::class)
        ->and(fn () => $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 60, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->overtime->decide($this->manager, $this->employee, '2026-10-06', 0, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->overtime->decide($this->manager, $this->employee, '2026-10-02', 0, null))->toThrow(ValidationException::class);

    // Todo flexibilidad: sin destino.
    $flex = $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 0, null);
    expect($flex->destination)->toBeNull()->and($flex->flex_minutes)->toBe(120)->and($this->ledger->balance($this->employee->id))->toBe(0);
});

it('a tiempo parcial son horas complementarias, se pagan y no cuentan para el tope', function () {
    EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'part_time' => true]);

    expect(fn () => $this->overtime->decide($this->manager, $this->employee->fresh(), '2026-10-05', 60, OvertimeDestination::Compensate))->toThrow(ValidationException::class);

    $decision = $this->overtime->decide($this->manager, $this->employee->fresh(), '2026-10-05', 60, OvertimeDestination::Pay);
    expect($decision->hour_type)->toBe(HourType::Complementary)
        ->and($this->overtime->yearSummary($this->employee, 2026)['complementary_minutes'])->toBe(60)
        ->and($this->overtime->yearMinutes($this->employee->id, 2026))->toBe(0);
});

it('una decisión nueva del mismo día sustituye a la anterior y revierte lo que sumó al saldo; nada se borra', function () {
    $first = $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 120, OvertimeDestination::Compensate);
    $second = $this->overtime->decide($this->hr, $this->employee, '2026-10-05', 60, OvertimeDestination::Pay);

    expect($second->supersedes_id)->toBe($first->id)
        ->and(OvertimeDecision::query()->count())->toBe(2)
        ->and(OvertimeDecision::query()->effective()->sole()->id)->toBe($second->id)
        ->and($this->ledger->balance($this->employee->id))->toBe(0)
        ->and(TimeBalanceMovement::query()->pluck('minutes')->all())->toBe([160, -160]);

    expect(inSavepoint(fn () => DB::table('overtime_decisions')->where('id', $first->id)->update(['overtime_minutes' => 1])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('overtime_decisions')->where('id', $first->id)->delete()))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('time_balance_movements')->delete()))->toThrow(QueryException::class);
});

it('si una corrección cambia el exceso, la clasificación queda por revisar', function () {
    $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 120, OvertimeDestination::Pay);
    expect($this->overtime->pending($this->manager, '2026-10-01', '2026-10-31'))->toBe([]);

    $service = app(ClockCorrectionService::class);
    $rows = array_map(fn ($event): array => ['id' => $event->id, 'kind' => $event->kind->value, 'time' => $event->occurred_at->setTimezone('Europe/Madrid')->format('H:i')], $service->dayEvents($this->employee->id, '2026-10-05'));
    $rows[3]['time'] = '19:00';
    unset($rows[3]['id']);
    $service->accept($this->employee, $service->propose($this->manager, $this->employee, '2026-10-05', $rows, 'Salió a las 19:00, no a las 20:00.'));

    $pending = $this->overtime->pending($this->manager, '2026-10-01', '2026-10-31');
    expect($pending)->toHaveCount(1)
        ->and($pending[0]['stale'])->toBeTrue()
        ->and($pending[0]['excess_minutes'])->toBe(60);
});

it('un mes confirmado no se clasifica sin desconfirmarlo', function () {
    $person = userWithRole('employee', ['department_id' => $this->employee->department_id]);
    workday($person, '2026-09-29', '09:00', '19:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-06 10:00'));
    $closer = app(MonthCloser::class);
    $closer->confirm($person, $closer->generate($person, CarbonImmutable::parse('2026-09-01')));

    expect(fn () => $this->overtime->decide($this->manager, $person, '2026-09-29', 60, OvertimeDestination::Pay))->toThrow(ValidationException::class);
});

it('avisa a RR. HH. y al responsable al pasar de 60 h y al llegar a 80 h de horas extra en el año (contando también las compensadas)', function () {
    // 30 días de 2 h extra = 60 h; 10 más = 80 h.
    Setting::set('people_register_starts_on', '2026-06-01');
    $person = userWithRole('employee', ['department_id' => $this->employee->department_id]);
    $date = CarbonImmutable::parse('2026-06-01');
    $days = [];

    while (count($days) < 40) {
        if ($date->isWeekday()) {
            workday($person, $date->toDateString(), '08:00', '19:00', '14:00', '15:00');
            $days[] = $date->toDateString();
        }

        $date = $date->addDay();
    }

    $this->travelTo(madridAt('2026-10-06 10:00'));
    $decided = 0;

    foreach ($days as $day) {
        $this->overtime->decide($this->manager, $person, $day, 120, $decided % 2 === 0 ? OvertimeDestination::Compensate : OvertimeDestination::Pay);
        $decided++;

        if ($decided === 29) {
            Notification::assertNothingSent();
        }

        if ($decided === 30) {
            Notification::assertSentTo([$this->manager, $this->hr], OvertimeCapReached::class, fn (OvertimeCapReached $notification) => ! $notification->over);
        }
    }

    Notification::assertSentTo([$this->manager, $this->hr], OvertimeCapReached::class, fn (OvertimeCapReached $notification) => $notification->over);
    $summary = $this->overtime->yearSummary($person, 2026);
    expect($summary['overtime_minutes'])->toBe(80 * 60)->and($summary['level'])->toBe('over')->and($summary['remaining_minutes'])->toBe(0);
});

it('los lunes envía a cada persona el resumen de sus horas extra de la semana anterior (obligatorio)', function () {
    $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 90, OvertimeDestination::Compensate);
    $quiet = userWithRole('employee');

    $this->travelTo(madridAt('2026-10-12 08:00'));
    $this->artisan('people:overtime-summary')->assertSuccessful();

    Notification::assertSentTo($this->employee, OvertimeWeeklySummary::class, fn (OvertimeWeeklySummary $notification) => $notification->summary['overtime_minutes'] === 90
        && $notification->summary['days'][0]['date'] === '2026-10-05');
    Notification::assertNotSentTo($quiet, OvertimeWeeklySummary::class);

    expect(app(NotificationCatalog::class)->find('people.overtime_weekly_summary')->mandatory)->toBeTrue();
});

it('el saldo de horas: descansos y pagos los anota el responsable o RR. HH.; ajustes y saldo inicial, solo RR. HH.; nunca en negativo', function () {
    $this->overtime->decide($this->manager, $this->employee, '2026-10-05', 120, OvertimeDestination::Compensate);

    expect(fn () => $this->ledger->record($this->employee, $this->employee, BalanceMovementKind::RestTaken, 60, '2026-10-06', 'Me lo tomo yo'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ledger->record($this->manager, $this->employee, BalanceMovementKind::Adjustment, 30, '2026-10-06', 'Ajuste de prueba'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ledger->record($this->manager, $this->employee, BalanceMovementKind::RestTaken, 200, '2026-10-06', 'Tarde libre'))->toThrow(ValidationException::class)
        ->and(fn () => $this->ledger->record($this->manager, $this->employee, BalanceMovementKind::RestTaken, 60, '2026-10-06', 'no'))->toThrow(ValidationException::class);

    $rest = $this->ledger->record($this->manager, $this->employee, BalanceMovementKind::RestTaken, 100, '2026-10-06', 'Salida a las 15:00 el viernes');
    expect($rest->minutes)->toBe(-100)->and($this->ledger->balance($this->employee->id))->toBe(60);

    $this->ledger->record($this->hr, $this->employee, BalanceMovementKind::OpeningBalance, 300, '2026-10-01', 'Saldo inicial desde Woffu a 01/10/2026');
    expect($this->ledger->balance($this->employee->id))->toBe(360);

    // Los cargos se descuentan de los abonos más antiguos primero (el saldo inicial del día 1).
    $pending = $this->ledger->pendingCompensation($this->employee->id);
    expect($pending[0]['date'])->toBe('2026-10-01')->and($pending[0]['remaining_minutes'])->toBe(200)
        ->and($pending[1]['remaining_minutes'])->toBe(160);

    // Al cabo de 4 meses, lo no disfrutado sale como vencido.
    expect($this->ledger->pendingCompensation($this->employee->id, CarbonImmutable::parse('2027-02-10'))[1]['expired'])->toBeTrue();
});
