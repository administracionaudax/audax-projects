<?php

use App\Enums\ClockEventKind;
use App\Enums\Role;
use App\Models\EmploymentProfile;
use App\Models\Setting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Matriz de permisos del registro de jornada (PLAN-FASE-11 §9; D-342), por rol y por ruta:
| la persona ve y corrige lo suyo; su responsable, lo de su departamento; RR. HH. (manage-people) y
| los admins, todo; un compañero, nada; un colaborador externo y un cliente, nunca.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    $this->travelTo(madridAt('2026-10-07 12:00'));

    $this->employee = userWithRole('employee');
    $this->colleague = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->colleague);
    ['manager' => $this->otherManager] = peopleTeam();
    $this->stranger = userWithRole('employee', ['department_id' => $this->otherManager->department_id]);
    $this->hr = tap(userWithRole('employee'), fn (User $user) => $user->givePermissionTo('manage-people'));
    $this->admin = userWithRole('admin');
    $this->collaborator = User::factory()->withRole(Role::Collaborator)->create();
    $this->client = userWithRole('client');
});

function peopleActor(string $who): User
{
    return match ($who) {
        'persona' => test()->employee,
        'compañero' => test()->colleague,
        'responsable' => test()->manager,
        'otro responsable' => test()->otherManager,
        'otro departamento' => test()->stranger,
        'rrhh' => test()->hr,
        'admin' => test()->admin,
        'colaborador' => test()->collaborator,
        'cliente' => test()->client,
    };
}

it('Mi jornada: toda la plantilla interna; ni colaboradores ni clientes', function (string $who, int $status) {
    $this->actingAs(peopleActor($who))->get('/personas/jornada')->assertStatus($status);
})->with([
    ['persona', 200], ['responsable', 200], ['rrhh', 200], ['admin', 200],
    ['colaborador', 403], ['cliente', 302],
]);

it('Mi jornada pinta el diario del mes, los totales y el día abierto', function () {
    workday($this->employee, '2026-10-06', '09:00', '17:00');

    $this->actingAs($this->employee)
        ->get('/personas/jornada?dia=2026-10-06')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/workday')
            ->where('month', '2026-10')
            ->where('subject.is_me', true)
            ->has('days', 31)
            ->where('detail.date', '2026-10-06')
            ->where('detail.day.worked_minutes', 480)
            ->has('detail.history', 2)
            ->where('detail.logged_minutes', 0)
            ->where('can.propose', true));
});

it('fichar: quien está sujeto al registro; no un exento, ni un colaborador, ni un cliente', function (string $who, int $status) {
    $this->actingAs(peopleActor($who))->post('/fichar', ['kind' => 'clock_in', 'work_mode' => 'on_site'])->assertStatus($status);
})->with([
    ['persona', 302], ['responsable', 302], ['admin', 302],
    ['colaborador', 403], ['cliente', 302],
]);

it('un exento no ficha', function () {
    EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'subject_to_register' => false, 'register_exemption_reason' => 'Socio no asalariado']);

    $this->actingAs($this->employee)->post('/fichar', ['kind' => 'clock_in'])->assertForbidden();
});

it('Jornada del equipo y Pendientes: responsables, RR. HH. y admins', function (string $who, int $status) {
    $this->actingAs(peopleActor($who))->get('/personas/equipo')->assertStatus($status);
    $this->actingAs(peopleActor($who))->get('/personas/pendientes')->assertStatus($status);
})->with([
    ['persona', 403], ['responsable', 200], ['rrhh', 200], ['admin', 200], ['colaborador', 403],
]);

it('el responsable solo ve a su departamento; RR. HH., a todos', function () {
    $this->actingAs($this->manager)->get('/personas/equipo')
        ->assertInertia(fn (Assert $page) => $page->component('people/team')->has('members', 3));

    $this->actingAs($this->hr)->get('/personas/equipo')
        ->assertInertia(fn (Assert $page) => $page->has('members', User::query()->role(['admin', 'department_manager', 'employee'])->where('is_active', true)->count()));
});

it('la jornada de una persona: ella (que va a la suya), su responsable, RR. HH. y los admins', function (string $who, int $status) {
    $this->actingAs(peopleActor($who))->get("/personas/equipo/{$this->employee->id}")->assertStatus($status);
    $this->actingAs(peopleActor($who))->get("/personas/equipo/{$this->employee->id}/filas?dia=2026-10-06")->assertStatus($status === 302 ? 200 : $status);
})->with([
    ['persona', 302], ['responsable', 200], ['rrhh', 200], ['admin', 200],
    ['compañero', 403], ['otro responsable', 403], ['otro departamento', 403], ['colaborador', 403],
]);

it('el responsable no ve la comparación con las horas imputadas (solo la persona)', function () {
    workday($this->employee, '2026-10-06', '09:00', '17:00');

    $this->actingAs($this->manager)->get("/personas/equipo/{$this->employee->id}?dia=2026-10-06")
        ->assertInertia(fn (Assert $page) => $page->where('detail.logged_minutes', null)->where('subject.is_me', false));
});

it('datos laborales: RR. HH. y los admins', function (string $who, int $status) {
    $this->actingAs(peopleActor($who))
        ->put("/admin/usuarios/{$this->employee->id}/laboral", ['hire_date' => '2024-02-01', 'subject_to_register' => true])
        ->assertStatus($status);
})->with([
    ['rrhh', 302], ['admin', 302], ['responsable', 403], ['persona', 403], ['colaborador', 403],
]);

it('los datos laborales exigen motivo para no fichar y la baja no antes del alta', function () {
    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$this->employee->id}/laboral", ['subject_to_register' => false, 'register_exemption_reason' => ''])
        ->assertSessionHasErrors('register_exemption_reason');

    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$this->employee->id}/laboral", ['hire_date' => '2026-01-10', 'termination_date' => '2025-12-31', 'subject_to_register' => true])
        ->assertSessionHasErrors('termination_date');

    $this->actingAs($this->admin)
        ->put("/admin/usuarios/{$this->employee->id}/laboral", ['hire_date' => '2024-02-01', 'subject_to_register' => false, 'register_exemption_reason' => 'Socio no asalariado'])
        ->assertSessionHasNoErrors();

    expect(EmploymentProfile::query()->where('user_id', $this->employee->id)->sole())
        ->hire_date->toDateString()->toBe('2024-02-01')
        ->subject_to_register->toBeFalse();
});

it('con el módulo apagado, 404 para todos salvo los admins en modo de prueba', function () {
    Setting::set('modules', [...(array) Setting::get('modules'), 'people' => false]);

    $this->actingAs($this->employee)->get('/personas/jornada')->assertNotFound();
    $this->actingAs($this->employee)->post('/fichar', ['kind' => 'clock_in'])->assertNotFound();
    $this->actingAs($this->admin)->get('/personas/jornada')->assertNotFound();

    Setting::set('modules_preview', true);

    $this->actingAs($this->admin)->get('/personas/jornada')->assertOk();
    $this->actingAs($this->employee)->get('/personas/jornada')->assertNotFound();
});

it('la cabecera recibe el estado del registro de quien ficha', function () {
    punchAt($this->employee, '2026-10-07 09:00', ClockEventKind::ClockIn);
    $this->travelTo(madridAt('2026-10-07 12:00'));

    $this->actingAs($this->employee)->get('/personas/jornada')
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.clock.status', 'working')
            ->where('people.clock.running_since', '2026-10-07T07:00:00Z')
            ->where('people.clock.worked_seconds', 0)
            ->where('people.clock.work_mode', 'on_site')
            ->where('auth.can.clock', true)
            ->where('auth.can.viewPeopleTeam', false));

    $this->actingAs($this->collaborator)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('people', null)->where('auth.can.clock', false));
});
