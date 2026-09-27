<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Páginas de ausencias (D-049, D-021): «Mis ausencias» (/ausencias), «Ausencias del equipo»
| (/ausencias/equipo) y el contador de /ausencias/equipo/pendientes; qué ve cada rol y la matriz
| de permisos de cada ruta. "Hoy" es el jueves 24/09/2026.
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));

    $this->design = Department::factory()->create(['name' => 'Diseño', 'color' => '#0171FF']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing', 'color' => '#FF6B00']);
    $this->manager = User::factory()->departmentManager()->inDepartment($this->design)->create(['name' => 'Raúl']);
    $this->design->managers()->attach($this->manager);
    $this->employee = User::factory()->employee()->inDepartment($this->design)->create(['name' => 'Elena']);
    $this->colleague = User::factory()->employee()->inDepartment($this->design)->create(['name' => 'Bruno']);
    $this->marketer = User::factory()->employee()->inDepartment($this->marketing)->create(['name' => 'Marta']);
    $this->admin = User::factory()->admin()->create(['name' => 'Ana']);

    // Gestor de proyecto: empleado de Marketing que gestiona un proyecto (D-005).
    $this->projectManager = User::factory()->employee()->inDepartment($this->marketing)->create(['name' => 'Gema']);
    Project::factory()->create()->addMember($this->projectManager, true);

    $this->service = app(AbsenceService::class);
});

test('«Mis ausencias» muestra solo las de quien mira, con días laborables, revisor y acciones', function () {
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
    $pending = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-12', '2026-10-16', notes: 'Viaje'));
    $approved = $this->service->request($this->employee, new AbsenceData(AbsenceType::Leave, '2026-11-02', '2026-11-02', 120));
    $this->service->approve($this->manager, $approved);
    Absence::factory()->for($this->employee)->between('2026-09-01', '2026-09-02')->create(['status' => AbsenceStatus::Rejected, 'approved_by' => $this->manager->id, 'review_comment' => 'No']);
    Absence::factory()->for($this->colleague)->between('2026-10-05', '2026-10-06')->create();

    $this->actingAs($this->employee)
        ->get('/ausencias')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('absences/index')
            ->has('absences', 3)
            ->where('absences.0.id', $approved->id)
            ->where('absences.0.status', 'approved')
            ->where('absences.0.partial_minutes', 120)
            ->where('absences.0.working_days', null)
            ->where('absences.0.reviewer.name', 'Raúl')
            ->where('absences.0.can.cancel', true)
            ->where('absences.1.id', $pending->id)
            ->where('absences.1.working_days', 4)
            ->where('absences.1.notes', 'Viaje')
            ->where('absences.1.can', ['cancel' => true, 'review' => false, 'update' => false])
            ->where('absences.2.status', 'rejected')
            ->where('absences.2.review_comment', 'No')
            ->where('absences.2.can.cancel', false)
            ->where('types', ['vacation', 'sick', 'leave', 'training', 'other'])
            ->where('today', '2026-09-24')
            ->where('limits', ['from' => '2024-09-24', 'to' => '2028-09-24'])
            ->where('self_approves', false)
            ->where('can.team', false));
});

test('un responsable ve que sus ausencias se aprueban solas y el acceso al equipo', function () {
    $this->actingAs($this->manager)
        ->get('/ausencias')
        ->assertInertia(fn (Assert $page) => $page
            ->where('self_approves', true)
            ->where('can.team', true)
            ->has('absences', 0));
});

test('«Ausencias del equipo»: un responsable ve las de su departamento con las que coinciden', function () {
    $mine = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $this->service->request($this->colleague, new AbsenceData(AbsenceType::Training, '2026-10-07', '2026-10-07'));
    $this->service->request($this->marketer, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    Absence::factory()->for($this->manager)->approved()->between('2026-09-28', '2026-09-30')->create(['type' => AbsenceType::Sick]);
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);

    $this->actingAs($this->manager)
        ->get('/ausencias/equipo?mes=2026-10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('absences/team')
            ->has('pending', 2)
            ->where('pending.0.id', $mine->id)
            ->where('pending.0.user.name', 'Elena')
            ->where('pending.0.user.department.name', 'Diseño')
            ->where('pending.0.working_days', 5)
            ->where('pending.0.can', ['cancel' => false, 'review' => true, 'update' => false])
            ->has('pending.0.overlaps', 1)
            ->where('pending.0.overlaps.0.user_name', 'Bruno')
            ->where('pending.0.overlaps.0.type', 'training')
            ->has('upcoming', 1)
            ->where('upcoming.0.user.name', 'Raúl')
            ->where('upcoming.0.can', ['cancel' => true, 'review' => false, 'update' => false])
            ->where('calendar.month', '2026-10')
            ->where('calendar.current', '2026-09')
            ->where('calendar.previous', '2026-09')
            ->where('calendar.next', '2026-11')
            ->has('calendar.days', 31)
            ->has('calendar.people', 3)
            ->has('calendar.absences', 2)
            ->where('calendar.holidays', [['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']])
            ->where('departments', [['id' => $this->design->id, 'name' => 'Diseño', 'color' => '#0171FF']])
            ->where('register_people', [
                ['id' => $this->colleague->id, 'name' => 'Bruno'],
                ['id' => $this->employee->id, 'name' => 'Elena'],
                ['id' => $this->manager->id, 'name' => 'Raúl'],
            ])
            ->where('filters.department', null));
});

test('un admin ve a todo el equipo y puede filtrar por departamento', function () {
    $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $this->service->request($this->marketer, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));

    $this->actingAs($this->admin)
        ->get('/ausencias/equipo')
        ->assertInertia(fn (Assert $page) => $page
            ->has('pending', 2)
            ->has('departments', 2)
            ->where('calendar.month', '2026-09'));

    $this->actingAs($this->admin)
        ->get("/ausencias/equipo?departamento={$this->marketing->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('pending', 1)
            ->where('pending.0.user.name', 'Marta')
            ->where('filters.department', $this->marketing->id)
            ->has('calendar.people', 2));
});

test('un filtro de departamento ajeno o un mes mal escrito se ignoran', function () {
    $this->actingAs($this->manager)
        ->get("/ausencias/equipo?departamento={$this->marketing->id}&mes=2026-13")
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.department', null)
            ->where('calendar.month', '2026-09')
            ->has('calendar.people', 3));
});

test('el contador de pendientes cuenta solo las que puede revisar quien mira', function () {
    $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $this->service->request($this->colleague, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $this->service->request($this->marketer, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));

    $this->actingAs($this->manager)->getJson('/ausencias/equipo/pendientes')->assertOk()->assertExactJson(['count' => 2]);
    $this->actingAs($this->admin)->getJson('/ausencias/equipo/pendientes')->assertExactJson(['count' => 3]);
});

test('Inicio: la tarjeta «Mis ausencias» trae las próximas aprobadas y las pendientes', function () {
    Absence::factory()->for($this->employee)->approved()->between('2026-09-21', '2026-09-25')->create();
    Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create(['type' => AbsenceType::Training]);
    Absence::factory()->for($this->employee)->between('2026-12-21', '2026-12-31')->create();
    Absence::factory()->for($this->employee)->approved()->between('2026-09-01', '2026-09-02')->create();
    Absence::factory()->for($this->employee)->between('2026-10-01', '2026-10-01')->create(['status' => AbsenceStatus::Rejected]);
    Absence::factory()->for($this->colleague)->approved()->between('2026-10-01', '2026-10-01')->create();

    $this->actingAs($this->employee)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('absences.upcoming', 2)
            ->where('absences.upcoming.0.start_date', '2026-09-21')
            ->where('absences.upcoming.1.type', 'training')
            ->has('absences.pending', 1)
            ->where('absences.pending.0', [
                'id' => Absence::query()->where('start_date', '2026-12-21')->value('id'),
                'type' => 'vacation',
                'status' => 'requested',
                'start_date' => '2026-12-21',
                'end_date' => '2026-12-31',
                'partial_minutes' => null,
            ]));
});

/*
| Matriz de permisos por rol (D-049, D-021): admin, responsable, gestor de proyecto, empleado,
| cliente e invitado en cada ruta. Estado esperado de cada una.
*/
test('matriz de permisos de las rutas de ausencias', function (string $role, array $expected) {
    $actor = match ($role) {
        'admin' => $this->admin,
        'responsable' => $this->manager,
        'gestor' => $this->projectManager,
        'empleado' => $this->colleague,
        'cliente' => userWithRole('client'),
        'invitado' => null,
    };

    // Una solicitud de Elena (Diseño) para aprobar, otra para rechazar, una aprobada para anular y
    // otra para modificar.
    $toApprove = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-06'));
    $toReject = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-13', '2026-10-14'));
    $toAnnul = Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create();
    $toEdit = Absence::factory()->for($this->employee)->approved()->between('2026-11-16', '2026-11-20')->create(['type' => AbsenceType::Sick]);

    $requests = [
        'index' => fn () => $this->get('/ausencias'),
        'store' => fn () => $this->post('/ausencias', ['type' => 'vacation', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01']),
        'team' => fn () => $this->get('/ausencias/equipo'),
        'pending' => fn () => $this->get('/ausencias/equipo/pendientes'),
        'register' => fn () => $this->post('/ausencias/equipo', ['user_id' => $this->employee->id, 'type' => 'sick', 'start_date' => '2026-12-14', 'end_date' => '2026-12-15']),
        'approve' => fn () => $this->post("/ausencias/{$toApprove->id}/aprobar"),
        'reject' => fn () => $this->post("/ausencias/{$toReject->id}/rechazar", ['comment' => 'No puede ser']),
        'annul' => fn () => $this->post("/ausencias/{$toAnnul->id}/cancelar"),
        'update' => fn () => $this->put("/ausencias/{$toEdit->id}", ['type' => 'sick', 'start_date' => '2026-11-16', 'end_date' => '2026-11-18']),
    ];

    $statuses = [];
    foreach ($requests as $name => $request) {
        if ($actor !== null) {
            $this->actingAs($actor);
        }

        $response = $request();
        $statuses[$name] = match (true) {
            $response->isRedirect(route('login')) => 'login',
            $response->isRedirect(route('portal.home')) => 'portal',
            default => $response->getStatusCode(),
        };
    }

    expect($statuses)->toBe($expected);
})->with([
    'admin' => ['admin', ['index' => 200, 'store' => 302, 'team' => 200, 'pending' => 200, 'register' => 302, 'approve' => 302, 'reject' => 302, 'annul' => 302, 'update' => 302]],
    'responsable' => ['responsable', ['index' => 200, 'store' => 302, 'team' => 200, 'pending' => 200, 'register' => 302, 'approve' => 302, 'reject' => 302, 'annul' => 302, 'update' => 302]],
    'gestor' => ['gestor', ['index' => 200, 'store' => 302, 'team' => 403, 'pending' => 403, 'register' => 403, 'approve' => 403, 'reject' => 403, 'annul' => 403, 'update' => 403]],
    'empleado' => ['empleado', ['index' => 200, 'store' => 302, 'team' => 403, 'pending' => 403, 'register' => 403, 'approve' => 403, 'reject' => 403, 'annul' => 403, 'update' => 403]],
    'cliente' => ['cliente', ['index' => 'portal', 'store' => 'portal', 'team' => 'portal', 'pending' => 'portal', 'register' => 'portal', 'approve' => 'portal', 'reject' => 'portal', 'annul' => 'portal', 'update' => 'portal']],
    'invitado' => ['invitado', ['index' => 'login', 'store' => 'login', 'team' => 'login', 'pending' => 'login', 'register' => 'login', 'approve' => 'login', 'reject' => 'login', 'annul' => 'login', 'update' => 'login']],
]);

test('la matriz deja cada ausencia como corresponde a quien actuó', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-06'));

    $this->actingAs($this->projectManager)->post("/ausencias/{$absence->id}/aprobar")->assertForbidden();
    $this->actingAs($this->colleague)->post("/ausencias/{$absence->id}/cancelar")->assertForbidden();
    expect($absence->fresh()->status)->toBe(AbsenceStatus::Requested);

    $this->actingAs($this->manager)->post("/ausencias/{$absence->id}/aprobar")->assertRedirect();
    expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved);
});

test('un usuario desactivado no puede solicitar ni revisar', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-06'));
    $this->manager->update(['is_active' => false]);

    $this->actingAs($this->manager->fresh())->post("/ausencias/{$absence->id}/aprobar")->assertRedirect(route('login'));
    expect($absence->fresh()->status)->toBe(AbsenceStatus::Requested);
});
