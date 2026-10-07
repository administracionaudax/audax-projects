<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Absences\LeaveCalendar;
use App\Domain\Absences\LeaveLedger;
use App\Domain\People\WorkdayCalculator;
use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\CancellationStatus;
use App\Enums\LeaveCalendarDayKind;
use App\Models\Absence;
use App\Models\LeaveCalendarDay;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancellationDecidedNotification;
use App\Notifications\Absences\AbsenceCancellationRequestedNotification;
use App\Notifications\Absences\AbsenceRequestedNotification;
use App\Notifications\Absences\AbsenceSecondApprovalNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
| Solicitudes de vacaciones y permisos con el módulo `people` (Fase 11, R3; W-054 a W-069; D-364 a
| D-366): con saldo y sin él, por horas con franja, media jornada, días bloqueados, segundo nivel,
| «Pedir cancelación», avisos que no bloquean y el efecto en la jornada teórica del registro.
*/

beforeEach(function () {
    enablePeople();
    Notification::fake();
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
    $this->colleague = userWithRole('employee', ['name' => 'Lucía']);
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->colleague);
    $this->manager->forceFill(['name' => 'Raúl'])->save();
    $this->hr = hrUser(['name' => 'Toni']);
    $this->vacation = leaveType('vacation');
    app(LeaveLedger::class)->syncYear(2026);
    app(LeaveLedger::class)->syncYear(2027);
});

function requestLeave(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->post('/ausencias', $data);
}

describe('pedir con saldo', function () {
    it('pide vacaciones del catálogo con saldo: queda pendiente, avisa al responsable y reserva el saldo', function () {
        requestLeave($this->employee, ['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-18'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Solicitud enviada: Vacaciones del 14/12/2026 al 18/12/2026. Te avisaremos cuando la revisen.');

        $absence = Absence::query()->sole();
        expect($absence->leave_type_id)->toBe($this->vacation->id)
            ->and($absence->type)->toBe(AbsenceType::Vacation)
            ->and($absence->status)->toBe(AbsenceStatus::Requested);
        Notification::assertSentTo($this->manager, AbsenceRequestedNotification::class);

        $this->actingAs($this->employee)->get('/ausencias')->assertInertia(fn ($page) => $page
            ->where('leave.balances.0.type.name', 'Vacaciones')
            ->where('leave.balances.0.total', 2200)
            ->where('leave.balances.0.pending', 500)
            ->where('leave.balances.0.available', 1700)
            ->where('absences.0.leave.cost', 500)
            ->where('absences.0.leave.type.key', 'vacation'));
    });

    it('no deja pedir vacaciones sin saldo suficiente en esas fechas', function () {
        requestLeave($this->employee, ['leave_type_id' => $this->vacation->id, 'start_date' => '2026-11-02', 'end_date' => '2026-12-04'])
            ->assertSessionHasErrors(['start_date' => 'No tienes saldo suficiente de «Vacaciones»: pides 25 días y en esas fechas te quedan 22 días.']);

        expect(Absence::query()->count())->toBe(0);
    });

    it('quien registra por otra persona (responsable o RR. HH.) no tiene el límite del saldo: decide la empresa', function () {
        $this->actingAs($this->manager)->post('/ausencias/equipo', [
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->vacation->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-12-04',
        ])->assertSessionHasNoErrors();

        expect(Absence::query()->sole()->status)->toBe(AbsenceStatus::Approved);
    });

    it('la fuerza mayor deja pedir más de lo retribuido, con un aviso', function () {
        $response = $this->actingAs($this->employee)->postJson('/ausencias/simular', [
            'leave_type_id' => leaveType('force_majeure')->id,
            'start_date' => '2026-10-13',
            'end_date' => '2026-10-19',
        ])->assertOk();

        expect($response->json('errors'))->toBe([])
            ->and(collect($response->json('warnings'))->pluck('code')->all())->toContain('unpaid_excess', 'document')
            ->and($response->json('cost.label'))->toBe('40:00 h');
    });

    it('simula lo que cuesta, el saldo que queda y avisa de la antelación de 2 meses (art. 38.3 ET)', function () {
        $response = $this->actingAs($this->employee)->postJson('/ausencias/simular', [
            'leave_type_id' => $this->vacation->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-06',
        ])->assertOk();

        expect($response->json('cost'))->toBe(['amount' => 500, 'unit' => 'working_days', 'label' => '5 días'])
            ->and($response->json('balance.after_label'))->toBe('17 días')
            ->and(collect($response->json('warnings'))->pluck('code')->all())->toBe(['short_notice']);

        $later = $this->actingAs($this->employee)->postJson('/ausencias/simular', [
            'leave_type_id' => $this->vacation->id, 'start_date' => '2027-01-11', 'end_date' => '2027-01-15',
        ]);
        expect($later->json('warnings'))->toBe([]);
    });

    it('avisa del preaviso del permiso parental y de lo que pasa de los días del permiso', function () {
        $parental = $this->actingAs($this->employee)->postJson('/ausencias/simular', [
            'leave_type_id' => leaveType('parental')->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-16',
        ]);
        $marriage = $this->actingAs($this->employee)->postJson('/ausencias/simular', [
            'leave_type_id' => leaveType('marriage')->id, 'start_date' => '2027-05-01', 'end_date' => '2027-05-20',
        ]);

        expect(collect($parental->json('warnings'))->pluck('code')->all())->toContain('notice')
            ->and(collect($marriage->json('warnings'))->pluck('code')->all())->toContain('over_amount', 'document');
    });

    it('no deja pedir un tipo inactivo (los del convenio pendientes de asesor)', function () {
        requestLeave($this->employee, ['leave_type_id' => leaveType('own_affairs')->id, 'start_date' => '2026-11-02'])
            ->assertSessionHasErrors(['leave_type_id' => '«Asuntos propios» ya no se puede pedir.']);
    });
});

describe('por horas con franja', function () {
    it('guarda la franja y descuenta sus minutos de la jornada teórica del registro', function () {
        requestLeave($this->employee, [
            'leave_type_id' => leaveType('public_duty')->id,
            'start_date' => '2026-10-14',
            'start_time' => '10:00',
            'end_time' => '12:30',
            'notes' => 'Citación del juzgado',
        ])->assertSessionHasNoErrors();

        $absence = Absence::query()->sole();
        expect($absence->start_time)->toBe('10:00')
            ->and($absence->end_time)->toBe('12:30')
            ->and($absence->partial_minutes)->toBe(150)
            ->and($absence->end_date->toDateString())->toBe('2026-10-14');

        app(AbsenceService::class)->approve($this->manager, $absence);
        $day = app(WorkdayCalculator::class)->forUser($this->employee, '2026-10-14', '2026-10-14')['2026-10-14'];
        expect($day['capacity_minutes'])->toBe(330);
    });

    it('pide las dos horas, en orden, y no admite franja en un tipo por días', function () {
        requestLeave($this->employee, ['leave_type_id' => leaveType('public_duty')->id, 'start_date' => '2026-10-14', 'start_time' => '12:00', 'end_time' => '10:00'])
            ->assertSessionHasErrors(['end_time' => 'La hora de fin tiene que ser posterior a la de inicio.']);
        requestLeave($this->employee, ['leave_type_id' => leaveType('public_duty')->id, 'start_date' => '2026-10-14', 'start_time' => '12:00'])
            ->assertSessionHasErrors(['start_time' => 'Indica la hora de inicio y la de fin.']);
        requestLeave($this->employee, ['leave_type_id' => $this->vacation->id, 'start_date' => '2026-10-14', 'start_time' => '10:00', 'end_time' => '12:00'])
            ->assertSessionHasErrors(['start_time' => '«Vacaciones» se pide por días, sin franja horaria.']);
    });
});

describe('calendario: media jornada y días bloqueados', function () {
    it('un día de media jornada deja la teórica en la mitad y unas vacaciones ese día la dejan a cero', function () {
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::HalfDay, 'name' => 'Media jornada de Nochebuena', 'start_date' => '2026-12-24', 'end_date' => '2026-12-24']);
        LeaveCalendar::forget();

        $calculator = app(WorkdayCalculator::class);
        expect($calculator->forUser($this->employee, '2026-12-24', '2026-12-24')['2026-12-24']['capacity_minutes'])->toBe(240);

        Absence::factory()->for($this->employee)->approved()->between('2026-12-24', '2026-12-24')->create();
        expect($calculator->forUser($this->employee, '2026-12-24', '2026-12-24')['2026-12-24']['capacity_minutes'])->toBe(0);
    });

    it('no deja pedir vacaciones en días bloqueados, pero sí otros permisos; RR. HH. puede registrarlas', function () {
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::Blocked, 'name' => 'Cierre del trimestre', 'start_date' => '2026-12-28', 'end_date' => '2026-12-31']);
        LeaveCalendar::forget();

        requestLeave($this->employee, ['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-21', 'end_date' => '2026-12-29'])
            ->assertSessionHasErrors(['start_date' => 'Esas fechas están bloqueadas para las vacaciones (Cierre del trimestre, del 28/12/2026 al 31/12/2026). Habla con RR. HH. si necesitas cogerlas.']);
        requestLeave($this->employee, ['leave_type_id' => leaveType('moving')->id, 'start_date' => '2026-12-29'])->assertSessionHasNoErrors();

        $this->actingAs($this->hr)->post('/ausencias/equipo', [
            'user_id' => $this->colleague->id, 'leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-28', 'end_date' => '2026-12-28',
        ])->assertSessionHasNoErrors();
    });
});

describe('segundo nivel (solo vacaciones, si se activa)', function () {
    beforeEach(function () {
        $this->vacation->forceFill(['second_approval' => true])->save();
    });

    it('la aprueba primero el responsable, después RR. HH., y solo entonces resta capacidad', function () {
        requestLeave($this->employee, ['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-18'])->assertSessionHasNoErrors();
        $absence = Absence::query()->sole();

        $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/aprobar")
            ->assertInertiaFlash('toast.message', 'Primer nivel aprobado. Falta la aprobación de RR. HH.');
        $absence->refresh();
        expect($absence->status)->toBe(AbsenceStatus::Requested)
            ->and($absence->first_approved_by)->toBe($this->manager->id)
            ->and(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-12-15')))->toBe(480);
        Notification::assertSentTo($this->hr, AbsenceSecondApprovalNotification::class, fn ($n) => $n->title($this->hr) === 'Vacaciones de Elena por aprobar (RR. HH.): del 14/12/2026 al 18/12/2026');
        Notification::assertNotSentTo($this->employee, AbsenceApprovedNotification::class);

        // El responsable ya no puede volver a aprobarla.
        $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/aprobar")->assertForbidden();

        $this->actingAs($this->hr)->post("/ausencias/{$absence->id}/aprobar")->assertSessionHasNoErrors();
        expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved)
            ->and($absence->fresh()->approved_by)->toBe($this->hr->id)
            ->and(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-12-15')))->toBe(0);
        Notification::assertSentTo($this->employee, AbsenceApprovedNotification::class);
    });

    it('si RR. HH. la aprueba directamente, cuenta por los dos niveles', function () {
        $absence = app(AbsenceService::class)->request($this->employee, AbsenceData::fromInput(['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-14']));

        app(AbsenceService::class)->approve($this->hr, $absence);

        expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved)
            ->and($absence->fresh()->first_approved_by)->toBe($this->hr->id);
    });

    it('las de un responsable pasan solas el primer nivel y esperan a RR. HH.; las de RR. HH., los dos', function () {
        $own = app(AbsenceService::class)->request($this->manager, AbsenceData::fromInput(['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-15']));
        expect($own->status)->toBe(AbsenceStatus::Requested)->and($own->first_approved_at)->not->toBeNull();
        Notification::assertSentTo($this->hr, AbsenceSecondApprovalNotification::class);

        $admin = userWithRole('admin');
        $hrOwn = app(AbsenceService::class)->request($admin, AbsenceData::fromInput(['leave_type_id' => $this->vacation->id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-14']));
        expect($hrOwn->status)->toBe(AbsenceStatus::Approved);
    });

    it('no se puede activar en un tipo que no sea de vacaciones', function () {
        $marriage = leaveType('marriage');

        $this->actingAs($this->hr)->put("/ausencias/tipos/{$marriage->id}", [
            'name' => $marriage->name, 'category' => 'leave', 'unit' => 'calendar_days', 'second_approval' => true,
        ])->assertSessionHasErrors(['second_approval' => 'El segundo nivel de aprobación solo se puede activar en las vacaciones.']);
    });
});

describe('pedir cancelación', function () {
    it('la persona pide cancelar una aprobada que ya ha empezado; el responsable la acepta y el saldo vuelve', function () {
        $absence = Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-09')->create(['leave_type_id' => $this->vacation->id]);

        // Ya ha empezado: no se cancela sin más.
        $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/cancelar")->assertForbidden();

        $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/pedir-cancelacion", ['reason' => 'Me reincorporo antes'])
            ->assertSessionHasNoErrors();
        expect($absence->fresh()->cancellation_status)->toBe(CancellationStatus::Requested)
            ->and($absence->fresh()->status)->toBe(AbsenceStatus::Approved);
        Notification::assertSentTo($this->manager, AbsenceCancellationRequestedNotification::class, fn ($n) => $n->title($this->manager) === 'Elena pide cancelar vacaciones del 05/10/2026 al 09/10/2026');

        // Una compañera no decide; la persona, tampoco.
        $this->actingAs($this->colleague)->post("/ausencias/{$absence->id}/cancelacion", ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/cancelacion", ['decision' => 'accept'])->assertForbidden();

        $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/cancelacion", ['decision' => 'accept'])->assertSessionHasNoErrors();
        expect($absence->fresh()->status)->toBe(AbsenceStatus::Cancelled)
            ->and($absence->fresh()->cancellation_status)->toBe(CancellationStatus::Accepted)
            ->and($absence->fresh()->cancellation_decided_by)->toBe($this->manager->id);
        Notification::assertSentTo($this->employee, AbsenceCancellationDecidedNotification::class, fn ($n) => $n->accepted);
    });

    it('rechazarla pide un comentario y la ausencia sigue aprobada', function () {
        $absence = Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-09')->create();
        app(AbsenceService::class)->requestCancellation($this->employee, $absence, 'Ya no la necesito');

        $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/cancelacion", ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');
        $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/cancelacion", ['decision' => 'reject', 'comment' => 'Ya está disfrutada'])
            ->assertSessionHasNoErrors();

        expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved)
            ->and($absence->fresh()->cancellation_status)->toBe(CancellationStatus::Rejected)
            ->and($absence->fresh()->cancellation_comment)->toBe('Ya está disfrutada');
    });

    it('una aprobada que aún no ha empezado se cancela directamente, como en la Fase 3', function () {
        $absence = Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create();

        $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/pedir-cancelacion", ['reason' => 'No hace falta'])->assertForbidden();
        $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/cancelar")->assertSessionHasNoErrors();
        expect($absence->fresh()->status)->toBe(AbsenceStatus::Cancelled);
    });
});

describe('efecto en el registro de jornada', function () {
    it('una ausencia aprobada de día completo deja a cero la teórica y no da «Sin fichajes»', function () {
        Setting::set('people_register_starts_on', '2026-09-01');
        Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-06')->create();

        $days = app(WorkdayCalculator::class)->forUser($this->employee, '2026-10-05', '2026-10-06');

        expect($days['2026-10-05']['expected_minutes'])->toBe(0)
            ->and($days['2026-10-05']['incidents'])->toBe([])
            ->and($days['2026-10-05']['status'])->toBe('off')
            ->and($days['2026-10-05']['absence']['type'])->toBe('vacation');
    });

    it('una solicitud pendiente o rechazada no cambia la teórica', function () {
        Setting::set('people_register_starts_on', '2026-09-01');
        Absence::factory()->for($this->employee)->between('2026-10-05', '2026-10-05')->create();

        expect(app(WorkdayCalculator::class)->forUser($this->employee, '2026-10-05', '2026-10-05')['2026-10-05']['expected_minutes'])->toBe(480);
    });
});
