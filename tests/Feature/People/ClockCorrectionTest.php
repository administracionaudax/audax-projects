<?php

use App\Domain\People\ClockCorrectionService;
use App\Domain\People\RegisterIntegrity;
use App\Domain\People\WorkdayCalculator;
use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\CorrectionStatus;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\People\CorrectionAccepted;
use App\Notifications\People\CorrectionDisputed;
use App\Notifications\People\CorrectionRequested;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Correcciones con doble conformidad (PLAN-FASE-11 §7.4; D-335): la persona propone y su
| responsable (o RR. HH.) acepta; si la propone el responsable, acepta la persona; sin acuerdo,
| discrepancia con las dos versiones. Nada se sobrescribe.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();

    $this->employee = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee);
    $this->service = app(ClockCorrectionService::class);
    $this->day = workday($this->employee, '2026-10-05', '09:15', '18:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-06 10:00'));
});

/** Las filas del día como están, con la entrada cambiada a otra hora. */
function correctionRowsWithClockIn(array $events, string $time): array
{
    return [
        ['id' => $events[0]->id, 'kind' => 'clock_in', 'time' => $time, 'work_mode' => 'on_site'],
        ['id' => $events[1]->id, 'kind' => 'pause_start', 'time' => '14:00'],
        ['id' => $events[2]->id, 'kind' => 'pause_end', 'time' => '15:00', 'work_mode' => 'on_site'],
        ['id' => $events[3]->id, 'kind' => 'clock_out', 'time' => '18:00'],
    ];
}

function registerWorkedOn(User $user, string $date): int
{
    return app(WorkdayCalculator::class)->forUser($user, $date, $date, null, madridAt('2026-10-06 12:00'))[$date]['worked_minutes'];
}

it('la persona propone, su responsable acepta y el original sigue en la cadena, anulado', function () {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '08:45'), 'Entré a las 8:45 y olvidé fichar');

    expect($correction->status)->toBe(CorrectionStatus::Pending)
        ->and($correction->voids)->toBe([$this->day[0]->id])
        ->and($correction->adds)->toHaveCount(1)
        ->and(registerWorkedOn($this->employee, '2026-10-05'))->toBe(465);

    Notification::assertSentTo($this->manager, CorrectionRequested::class);
    Notification::assertNotSentTo($this->employee, CorrectionRequested::class);

    $this->service->accept($this->manager, $correction);
    $correction->refresh();

    expect($correction->status)->toBe(CorrectionStatus::Accepted)
        ->and($correction->decided_by)->toBe($this->manager->id)
        ->and($correction->hash)->toHaveLength(64)
        ->and(registerWorkedOn($this->employee, '2026-10-05'))->toBe(495)
        // El original no se toca: sigue con su hora y su huella; una fila `void` lo anula.
        ->and(ClockEvent::query()->findOrFail($this->day[0]->id)->occurred_at->toIso8601ZuluString())->toBe('2026-10-05T07:15:00Z')
        ->and(ClockEvent::query()->where('kind', 'void')->sole()->voided_event_id)->toBe($this->day[0]->id)
        ->and(ClockEvent::query()->where('correction_id', $correction->id)->where('kind', 'clock_in')->sole()->source)->toBe(ClockSource::Correction)
        ->and(ClockEvent::query()->where('correction_id', $correction->id)->where('kind', 'clock_in')->sole()->created_by)->toBe($this->manager->id)
        ->and(ClockEvent::query()->count())->toBe(6)
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();

    Notification::assertSentTo($this->employee, CorrectionAccepted::class);
});

it('nadie acepta su propia propuesta ni una corrección de su registro que haya propuesto él', function () {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '08:45'), 'Entré a las 8:45');

    expect(fn () => $this->service->accept($this->employee, $correction))->toThrow(AuthorizationException::class);

    // Un responsable también necesita la conformidad de otra persona para las suyas.
    $own = workday($this->manager, '2026-10-05', '09:00', '17:00');
    $this->travelTo(madridAt('2026-10-06 11:00'));
    $mine = $this->service->propose($this->manager, $this->manager, '2026-10-05', [
        ['id' => $own[0]->id, 'kind' => 'clock_in', 'time' => '08:30'],
        ['id' => $own[1]->id, 'kind' => 'clock_out', 'time' => '17:00'],
    ], 'Llegué antes');

    expect(fn () => $this->service->accept($this->manager, $mine))->toThrow(AuthorizationException::class);
});

it('quién da la conformidad de la empresa: su responsable, RR. HH. y un admin; ni un compañero ni el responsable de otro departamento', function (string $who, bool $allowed) {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '08:45'), 'Entré a las 8:45');

    $actor = match ($who) {
        'responsable' => $this->manager,
        'rrhh' => tap(userWithRole('employee'), fn (User $user) => $user->givePermissionTo('manage-people')),
        'admin' => userWithRole('admin'),
        'compañero' => tap(userWithRole('employee'), fn (User $user) => $user->forceFill(['department_id' => $this->employee->department_id])->save()),
        'otro responsable' => peopleTeam()['manager'],
    };

    $attempt = fn () => $this->service->accept($actor, $correction);

    if ($allowed) {
        expect($attempt())->toBeInstanceOf(ClockCorrection::class);
    } else {
        expect($attempt)->toThrow(AuthorizationException::class);
    }
})->with([
    ['responsable', true],
    ['rrhh', true],
    ['admin', true],
    ['compañero', false],
    ['otro responsable', false],
]);

it('si la propone su responsable, la acepta la persona (y ni RR. HH. puede hacerlo por ella)', function () {
    $hr = userWithRole('admin');
    $correction = $this->service->propose($this->manager, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '09:00'), 'El torno de la oficina registra las 9:00');

    Notification::assertSentTo($this->employee, CorrectionRequested::class);

    expect(fn () => $this->service->accept($this->manager, $correction))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->accept($hr, $correction))->toThrow(AuthorizationException::class);

    $this->service->accept($this->employee, $correction);

    expect($correction->refresh()->status)->toBe(CorrectionStatus::Accepted)
        ->and(registerWorkedOn($this->employee, '2026-10-05'))->toBe(480);
    Notification::assertSentTo($this->manager, CorrectionAccepted::class);
});

it('rechazar exige motivo y deja la discrepancia: constan las dos versiones y cuenta la original', function () {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '07:00'), 'Empecé a las 7');

    expect(fn () => $this->service->reject($this->manager, $correction, '  '))->toThrow(ValidationException::class);

    $this->service->reject($this->manager, $correction, 'A las 7 la oficina estaba cerrada');
    $correction->refresh();

    expect($correction->status)->toBe(CorrectionStatus::Disputed)
        ->and($correction->dispute_reason)->toBe('rejected')
        ->and($correction->decision_note)->toBe('A las 7 la oficina estaba cerrada')
        ->and($correction->adds[0]['occurred_at'])->toBe('2026-10-05T05:00:00Z')
        ->and(registerWorkedOn($this->employee, '2026-10-05'))->toBe(465)
        ->and(ClockEvent::query()->where('kind', 'void')->count())->toBe(0);

    $day = app(WorkdayCalculator::class)->forUser($this->employee, '2026-10-05', '2026-10-05', null, madridAt('2026-10-06 12:00'))['2026-10-05'];
    expect($day['status'])->toBe('disputed');

    Notification::assertSentTo($this->employee, CorrectionDisputed::class);
});

it('sin respuesta en 7 días queda en discrepancia por falta de respuesta y se avisa a las dos partes', function () {
    $correction = $this->service->propose($this->manager, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '09:00'), 'Ajuste de la entrada');

    $this->travelTo(madridAt('2026-10-13 09:59'));
    expect($this->service->expireOverdue())->toBe(0);

    $this->travelTo(madridAt('2026-10-13 10:01'));
    expect($this->service->expireOverdue())->toBe(1);

    $correction->refresh();
    expect($correction->status)->toBe(CorrectionStatus::Disputed)
        ->and($correction->dispute_reason)->toBe('no_answer')
        ->and($correction->decided_by)->toBeNull()
        ->and($correction->hash)->not->toBeNull();

    Notification::assertSentTo([$this->employee, $this->manager], CorrectionDisputed::class);
    expect(fn () => $this->service->accept($this->employee, $correction))->toThrow(ValidationException::class);
});

it('quien la propone la puede retirar mientras está pendiente; nadie más', function () {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '08:45'), 'Entré a las 8:45');

    expect(fn () => $this->service->withdraw($this->manager, $correction))->toThrow(AuthorizationException::class);

    $this->service->withdraw($this->employee, $correction);

    expect($correction->refresh()->status)->toBe(CorrectionStatus::Withdrawn)
        ->and(fn () => $this->service->accept($this->manager, $correction))->toThrow(ValidationException::class);
});

it('una sola corrección pendiente por persona y día', function () {
    $this->service->propose($this->employee, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '08:45'), 'Entré a las 8:45');

    expect(fn () => $this->service->propose($this->manager, $this->employee, '2026-10-05', correctionRowsWithClockIn($this->day, '09:00'), 'Otra'))->toThrow(ValidationException::class);
});

it('propone la salida que faltaba y el día deja de tener la incidencia', function () {
    $this->travelTo(madridAt('2026-10-07 09:00'));
    punchAt($this->employee, '2026-10-07 09:00', ClockEventKind::ClockIn);
    $this->travelTo(madridAt('2026-10-08 10:00'));

    $in = ClockEvent::query()->where('user_id', $this->employee->id)->orderByDesc('seq')->first();
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-07', [
        ['id' => $in->id, 'kind' => 'clock_in', 'time' => '09:00', 'work_mode' => $in->work_mode->value],
        ['kind' => 'clock_out', 'time' => '17:30'],
    ], 'Olvidé fichar la salida');

    expect($correction->voids)->toBe([]);

    $this->service->accept($this->manager, $correction);
    $day = app(WorkdayCalculator::class)->forUser($this->employee, '2026-10-07', '2026-10-07', null, madridAt('2026-10-08 12:00'))['2026-10-07'];

    // Ya no falta la salida; queda el aviso de 8,5 h seguidas sin pausa, que es real.
    expect($day['incidents'])->toBe(['long_stretch'])
        ->and($day['worked_minutes'])->toBe(510);
});

it('una jornada que olvidé fichar entera y otra que se fichó por error', function () {
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-02', [
        ['kind' => 'clock_in', 'time' => '09:00', 'work_mode' => 'remote'],
        ['kind' => 'clock_out', 'time' => '17:00'],
    ], 'No fiché en todo el día, trabajé en casa');
    $this->service->accept($this->manager, $correction);

    $mistake = $this->service->propose($this->employee, $this->employee, '2026-10-05', [], 'Ese día estaba de vacaciones');

    expect(registerWorkedOn($this->employee, '2026-10-02'))->toBe(480)
        ->and($mistake->voids)->toHaveCount(4)
        ->and($mistake->adds)->toBe([]);
});

it('valida el día que resultaría', function (array $rows, string $date = '2026-10-05') {
    $replace = fn (array $row): array => isset($row['id']) && is_string($row['id']) ? [...$row, 'id' => $this->day[(int) $row['id']]->id] : $row;

    expect(fn () => $this->service->propose($this->employee, $this->employee, $date, array_map($replace, $rows), 'Motivo de la prueba'))
        ->toThrow(ValidationException::class);
})->with([
    'sin cambios' => [[['id' => '0', 'kind' => 'clock_in', 'time' => '09:15'], ['id' => '1', 'kind' => 'pause_start', 'time' => '14:00'], ['id' => '2', 'kind' => 'pause_end', 'time' => '15:00'], ['id' => '3', 'kind' => 'clock_out', 'time' => '18:00']]],
    'un día pasado sin salida' => [[['id' => '0', 'kind' => 'clock_in', 'time' => '09:15']]],
    'la pausa antes de la entrada' => [[['kind' => 'pause_start', 'time' => '08:00'], ['id' => '0', 'kind' => 'clock_in', 'time' => '09:15'], ['id' => '3', 'kind' => 'clock_out', 'time' => '18:00']]],
    'dos entradas seguidas' => [[['id' => '0', 'kind' => 'clock_in', 'time' => '09:15'], ['kind' => 'clock_in', 'time' => '10:00'], ['id' => '3', 'kind' => 'clock_out', 'time' => '18:00']]],
    'una hora con mal formato' => [[['id' => '0', 'kind' => 'clock_in', 'time' => '9h'], ['id' => '3', 'kind' => 'clock_out', 'time' => '18:00']]],
    'en el futuro' => [[['kind' => 'clock_in', 'time' => '09:00'], ['kind' => 'clock_out', 'time' => '18:00']], '2026-10-06'],
    'un día que no ha llegado' => [[['kind' => 'clock_in', 'time' => '09:00'], ['kind' => 'clock_out', 'time' => '10:00']], '2026-10-07'],
    'una entrada del día siguiente' => [[['kind' => 'clock_in', 'time' => '09:00', 'next_day' => true], ['kind' => 'clock_out', 'time' => '10:00', 'next_day' => true]], '2026-10-04'],
    'se cruza con la jornada del día siguiente' => [[['kind' => 'clock_in', 'time' => '22:00'], ['kind' => 'clock_out', 'time' => '10:00', 'next_day' => true]], '2026-10-04'],
]);

it('al aceptar se vuelve a validar el día: si ha cambiado, no se aplica', function () {
    $this->travelTo(madridAt('2026-10-07 09:00'));
    $in = punchAt($this->employee, '2026-10-07 09:00', ClockEventKind::ClockIn);
    $this->travelTo(madridAt('2026-10-07 11:00'));

    // Propone que salió a las 10:30, pero sigue trabajando y ficha la pausa a las 14:00.
    $correction = $this->service->propose($this->employee, $this->employee, '2026-10-07', [
        ['id' => $in->id, 'kind' => 'clock_in', 'time' => '09:00', 'work_mode' => $in->work_mode->value],
        ['kind' => 'clock_out', 'time' => '10:30'],
    ], 'Salí a las 10:30');
    punchAt($this->employee, '2026-10-07 14:00', ClockEventKind::PauseStart);

    expect(fn () => $this->service->accept($this->manager, $correction))->toThrow(ValidationException::class)
        ->and($correction->refresh()->status)->toBe(CorrectionStatus::Pending);
});

it('por HTTP: proponer, aceptar en bloque, rechazar y retirar', function () {
    $this->actingAs($this->employee)
        ->post('/personas/correcciones', ['user_id' => $this->employee->id, 'date' => '2026-10-05', 'reason' => 'Entré a las 8:45', 'rows' => correctionRowsWithClockIn($this->day, '08:45')])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $first = ClockCorrection::query()->sole();

    $this->actingAs($this->employee)->post("/personas/correcciones/{$first->id}/aceptar")->assertForbidden();
    $this->actingAs($this->manager)->post('/personas/correcciones/aceptar', ['ids' => [$first->id]])->assertRedirect()->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe(CorrectionStatus::Accepted);

    $other = workday($this->employee, '2026-10-06', '09:00', '17:00');
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->actingAs($this->employee)
        ->post('/personas/correcciones', ['user_id' => $this->employee->id, 'date' => '2026-10-06', 'reason' => 'Salí más tarde', 'rows' => [
            ['id' => $other[0]->id, 'kind' => 'clock_in', 'time' => '09:00', 'work_mode' => 'on_site'],
            ['id' => $other[1]->id, 'kind' => 'clock_out', 'time' => '18:00'],
        ]])
        ->assertSessionHasNoErrors();
    $second = ClockCorrection::query()->latest('id')->firstOrFail();

    $this->actingAs($this->manager)->post("/personas/correcciones/{$second->id}/rechazar", ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->manager)->post("/personas/correcciones/{$second->id}/rechazar", ['note' => 'Salió a las 17'])->assertSessionHasNoErrors();
    expect($second->refresh()->status)->toBe(CorrectionStatus::Disputed);

    // Otra persona no puede proponer correcciones del registro de alguien que no supervisa.
    $this->actingAs(userWithRole('employee'))
        ->post('/personas/correcciones', ['user_id' => $this->employee->id, 'date' => '2026-10-05', 'reason' => 'Intento', 'rows' => []])
        ->assertForbidden();
});
