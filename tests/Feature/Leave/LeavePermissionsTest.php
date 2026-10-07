<?php

use App\Domain\Absences\LeaveLedger;
use App\Enums\AbsenceStatus;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Matriz de permisos de R3 (PLAN-FASE-11 §9; D-370) y /ausencias con el módulo apagado: sigue
| exactamente como en la Fase 3 (D-360).
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
    $this->colleague = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->colleague);
    ['manager' => $this->otherManager] = peopleTeam();
    $this->hr = hrUser();
    $this->admin = userWithRole('admin');
    $this->collaborator = User::factory()->withRole(Role::Collaborator)->create();
    $this->client = userWithRole('client');
});

function leaveActor(string $who): User
{
    return match ($who) {
        'persona' => test()->employee,
        'responsable' => test()->manager,
        'rrhh' => test()->hr,
        'admin' => test()->admin,
        'colaborador' => test()->collaborator,
        'cliente' => test()->client,
    };
}

describe('con el módulo encendido', function () {
    beforeEach(fn () => enablePeople());

    it('Calendario laboral: toda la plantilla', function (string $who, int $status) {
        $this->actingAs(leaveActor($who))->get('/ausencias/calendario')->assertStatus($status);
    })->with([
        ['persona', 200], ['responsable', 200], ['rrhh', 200], ['admin', 200], ['colaborador', 403], ['cliente', 302],
    ]);

    it('Saldos: responsables (su equipo), RR. HH. y admins', function (string $who, int $status) {
        $this->actingAs(leaveActor($who))->get('/ausencias/saldos')->assertStatus($status);
    })->with([
        ['persona', 403], ['responsable', 200], ['rrhh', 200], ['admin', 200], ['colaborador', 403],
    ]);

    it('Tipos, movimientos del saldo y días especiales: solo RR. HH.', function (string $who, int $status) {
        $actor = leaveActor($who);
        $vacation = leaveType('vacation');

        $this->actingAs($actor)->get('/ausencias/tipos')->assertStatus($status);
        $this->actingAs($actor)->post('/ausencias/saldos/movimientos', [
            'user_id' => $this->employee->id, 'leave_type_id' => $vacation->id, 'kind' => 'adjustment', 'amount' => '1', 'year' => 2026, 'reason' => 'Ajuste de prueba',
        ])->assertStatus($status === 200 ? 302 : $status);
        $this->actingAs($actor)->post('/ausencias/calendario/dias', ['kind' => 'blocked', 'name' => 'Cierre', 'start_date' => '2026-12-28'])
            ->assertStatus($status === 200 ? 302 : $status);
    })->with([
        ['persona', 403], ['responsable', 403], ['rrhh', 200], ['admin', 200],
    ]);

    it('un responsable ve los saldos de su equipo, no los de otros; RR. HH., los de todos', function () {
        app(LeaveLedger::class)->syncYear(2026);
        $outsider = userWithRole('employee', ['name' => 'Zoe']);

        $this->actingAs($this->manager)->get('/ausencias/saldos')->assertInertia(fn (Assert $page) => $page
            ->component('leave/balances')
            ->where('can.manage', false)
            ->where('people', fn ($people) => collect($people)->pluck('id')->sort()->values()->all() === collect([$this->employee->id, $this->colleague->id, $this->manager->id])->sort()->values()->all()));

        $this->actingAs($this->hr)->get('/ausencias/saldos?persona='.$outsider->id)->assertInertia(fn (Assert $page) => $page
            ->where('can.manage', true)
            ->where('detail.person.name', 'Zoe')
            ->where('people', fn ($people) => collect($people)->pluck('id')->contains($outsider->id)));
    });

    it('RR. HH. sin ser admin aprueba, rechaza y ve las ausencias de toda la plantilla', function () {
        $absence = Absence::factory()->for($this->employee)->between('2026-11-02', '2026-11-03')->create();

        $this->actingAs($this->hr)->get('/ausencias/equipo')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pending.0.id', $absence->id));
        $this->actingAs($this->hr)->post("/ausencias/{$absence->id}/aprobar")->assertSessionHasNoErrors();

        expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved);
    });

    it('en el calendario del equipo un compañero solo ve «Ausencia», nunca el motivo', function () {
        Absence::factory()->for($this->employee)->approved()->between('2026-10-12', '2026-10-14')->create(['leave_type_id' => leaveType('family_illness')->id, 'type' => 'leave']);

        $rows = collect($this->actingAs($this->colleague)->get('/calendario?personas=1&fecha=2026-10-12')->assertOk()->viewData('page')['props']['calendar']['rows']);
        $elena = $rows->first(fn (array $row): bool => ($row['person']['id'] ?? null) === $this->employee->id);

        expect($elena['days']['2026-10-12']['absence'])->toBe(['partial' => false, 'label' => null]);

        // Su responsable sí ve el tipo (D-088), con la etiqueta de la categoría.
        $asManager = collect($this->actingAs($this->manager)->get('/calendario?personas=1&fecha=2026-10-12')->viewData('page')['props']['calendar']['rows'])
            ->first(fn (array $row): bool => ($row['person']['id'] ?? null) === $this->employee->id);
        expect($asManager['days']['2026-10-12']['absence']['label'])->toBe('Permiso');
    });
});

describe('con el módulo apagado', function () {
    it('las rutas de R3 dan 404 y /ausencias es la de la Fase 3', function () {
        foreach (['/ausencias/calendario', '/ausencias/saldos', '/ausencias/tipos'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }
        $this->actingAs($this->employee)->postJson('/ausencias/simular', ['type' => 'vacation', 'start_date' => '2026-11-02'])->assertNotFound();

        $this->actingAs($this->employee)->get('/ausencias')->assertInertia(fn (Assert $page) => $page
            ->component('absences/index')
            ->where('types', ['vacation', 'sick', 'leave', 'training', 'other'])
            ->where('leave', null));
    });

    it('se pide como siempre con la categoría, sin saldos ni franja, y queda con el tipo del catálogo de esa categoría', function () {
        // Sin ningún saldo: con el módulo apagado no se mira.
        $this->actingAs($this->employee)->post('/ausencias', ['type' => 'vacation', 'start_date' => '2026-11-02', 'end_date' => '2026-12-31'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Solicitud enviada: Vacaciones del 02/11/2026 al 31/12/2026. Te avisaremos cuando la revisen.');

        $absence = Absence::query()->sole();
        expect($absence->leave_type_id)->toBe(leaveType('vacation')->id)
            ->and(LeaveMovement::query()->count())->toBe(0);

        $this->actingAs($this->employee)->post('/ausencias', ['type' => 'leave', 'start_date' => '2026-11-02', 'start_time' => '10:00', 'end_time' => '12:00'])
            ->assertSessionHasErrors('start_time');
    });

    it('en «Ausencias del equipo» no hay datos de R3 y RR. HH. sin ser admin no entra', function () {
        Absence::factory()->for($this->employee)->between('2026-11-02', '2026-11-03')->create();

        $this->actingAs($this->manager)->get('/ausencias/equipo')->assertInertia(fn (Assert $page) => $page
            ->where('leave', null)
            ->where('cancellations', [])
            ->missing('pending.0.leave'));
        $this->actingAs($this->hr)->get('/ausencias/equipo')->assertForbidden();
    });

    it('la tarea diaria no hace nada', function () {
        $this->artisan('people:leave-daily')->assertSuccessful();

        expect(LeaveMovement::query()->count())->toBe(0);
    });
});

it('el catálogo precargado: los cinco de siempre con su clave y los del Estatuto; los del convenio, inactivos y pendientes de asesor', function () {
    $types = LeaveType::query()->orderBy('sort')->get()->keyBy('key');

    expect($types->keys()->take(3)->all())->toBe(['vacation', 'sick', 'marriage'])
        ->and(collect(['vacation', 'sick', 'leave', 'training', 'other'])->every(fn ($key) => $types[$key]->category->value === $key))
        ->and($types['vacation']->annual_allowance)->toBe(2200)
        ->and($types['vacation']->respects_blocked_days)->toBeTrue()
        ->and($types['marriage']->default_amount)->toBe(1500)
        ->and($types['marriage']->unit->value)->toBe('calendar_days')
        ->and($types['force_majeure']->unit->value)->toBe('hours')
        ->and($types['force_majeure']->allowance_in_days)->toBeTrue()
        ->and($types['sick']->health_data)->toBeTrue()
        ->and($types['sick']->requires_document)->toBeFalse()
        ->and($types['parental']->notice_days)->toBe(10)
        ->and($types['parental']->paid)->toBeFalse()
        ->and($types['medical_accompaniment']->active)->toBeFalse()
        ->and($types['medical_accompaniment']->advisor_pending)->toBeTrue()
        ->and($types['own_affairs']->active)->toBeFalse();
});

it('las ausencias de antes de R3 quedan con el tipo de su categoría', function () {
    $absence = Absence::factory()->for($this->employee)->create(['type' => 'sick']);

    expect($absence->fresh()->leave_type_id)->toBe(leaveType('sick')->id);
});

it('RR. HH. edita un tipo y, si cambia la asignación, se recalcula el año con un movimiento nuevo', function () {
    enablePeople();
    app(LeaveLedger::class)->syncYear(2026);
    $vacation = leaveType('vacation');

    $this->actingAs($this->hr)->put("/ausencias/tipos/{$vacation->id}", [
        'name' => 'Vacaciones', 'category' => 'vacation', 'unit' => 'working_days', 'annual_allowance' => '23',
        'paid' => true, 'carry_over_until' => '03-31', 'allow_without_balance' => false, 'respects_blocked_days' => true, 'active' => true,
    ])->assertSessionHasNoErrors();

    expect($vacation->fresh()->annual_allowance)->toBe(2300)
        ->and(LeaveMovement::query()->where('user_id', $this->employee->id)->where('leave_type_id', $vacation->id)->where('year', 2026)->pluck('amount')->all())->toBe([2200, 100]);
});
