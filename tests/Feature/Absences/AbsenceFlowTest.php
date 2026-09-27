<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancelledNotification;
use App\Notifications\Absences\AbsenceRejectedNotification;
use App\Notifications\Absences\AbsenceRequestedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/*
| Flujo de las ausencias (SPEC §4.1, §9 y §13, D-049): solicitar, aprobar o rechazar con comentario,
| autoaprobación de responsables y admins, cancelar y anular, registrar una ya aprobada, solapes,
| parciales, avisos y efecto en la capacidad. "Hoy" es el jueves 24/09/2026.
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->manager = User::factory()->departmentManager()->inDepartment($this->design)->create(['name' => 'Raúl']);
    $this->coManager = User::factory()->departmentManager()->inDepartment($this->design)->create(['name' => 'Bea']);
    $this->design->managers()->attach([$this->manager->id, $this->coManager->id]);
    $this->employee = User::factory()->employee()->inDepartment($this->design)->create(['name' => 'Elena']);
    $this->admin = User::factory()->admin()->create(['name' => 'Ana']);
    $this->service = app(AbsenceService::class);

    $this->request = fn (array $data = [], ?User $user = null) => $this->actingAs($user ?? $this->employee)->post('/ausencias', [
        'type' => 'vacation',
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-09',
        ...$data,
    ]);
});

test('una empleada solicita vacaciones: queda solicitada y se avisa a los responsables de su departamento', function () {
    ($this->request)(['notes' => ' Viaje '])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Solicitud enviada: Vacaciones del 05/10/2026 al 09/10/2026. Te avisaremos cuando la revisen.');

    $absence = Absence::query()->sole();
    expect($absence->status)->toBe(AbsenceStatus::Requested)
        ->and($absence->user_id)->toBe($this->employee->id)
        ->and($absence->type)->toBe(AbsenceType::Vacation)
        ->and($absence->notes)->toBe('Viaje')
        ->and($absence->approved_by)->toBeNull()
        ->and($absence->reviewed_at)->toBeNull();

    Notification::assertSentTo([$this->manager, $this->coManager], AbsenceRequestedNotification::class, function (AbsenceRequestedNotification $notification, array $channels) {
        return $channels === ['database', 'mail']
            && $notification->kind() === 'absence.requested'
            && $notification->title($this->manager) === 'Elena ha solicitado vacaciones del 05/10/2026 al 09/10/2026'
            && $notification->body($this->manager) === '«Viaje»'
            && $notification->url($this->manager) === '/ausencias/equipo';
    });
    Notification::assertNotSentTo([$this->admin, $this->employee], AbsenceRequestedNotification::class);

    // Una solicitud no resta capacidad hasta que se aprueba.
    expect(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-10-05')))->toBe(480);
});

test('sin responsables en su departamento, o sin departamento, avisa a los admins', function (Closure $person) {
    $owner = $person->call($this);
    $otherAdmin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create();

    ($this->request)([], $owner)->assertSessionHasNoErrors();

    Notification::assertSentTo([$this->admin, $otherAdmin], AbsenceRequestedNotification::class);
    Notification::assertSentTimes(AbsenceRequestedNotification::class, 2);
})->with([
    'sin departamento' => [fn () => User::factory()->employee()->create()],
    'departamento sin responsables' => [fn () => User::factory()->employee()->inDepartment(Department::factory()->create())->create()],
]);

test('las ausencias de responsables y admins se aprueban solas y sin avisos', function (string $who) {
    $user = $this->{$who};

    ($this->request)(['type' => 'leave'], $user)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia registrada y aprobada: Permiso del 05/10/2026 al 09/10/2026.');

    $absence = Absence::query()->sole();
    expect($absence->status)->toBe(AbsenceStatus::Approved)
        ->and($absence->approved_by)->toBeNull()
        ->and($absence->reviewed_at)->not->toBeNull();

    Notification::assertNothingSent();
})->with(['manager', 'admin']);

test('un responsable aprueba: la persona recibe el aviso y su capacidad baja', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-06'));

    $this->actingAs($this->manager)
        ->post("/ausencias/{$absence->id}/aprobar")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena aprobada.');

    $absence->refresh();
    expect($absence->status)->toBe(AbsenceStatus::Approved)
        ->and($absence->approved_by)->toBe($this->manager->id)
        ->and($absence->reviewed_at)->not->toBeNull();

    Notification::assertSentTo($this->employee, AbsenceApprovedNotification::class, fn (AbsenceApprovedNotification $notification) => $notification->title($this->employee) === 'Ausencia aprobada: Vacaciones del 05/10/2026 al 06/10/2026'
        && $notification->body($this->employee) === 'La ha aprobado Raúl.'
        && $notification->url($this->employee) === '/ausencias'
        && $notification->icon() === 'calendar-check');

    expect(app(Capacity::class)->forRange($this->employee, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-07')))
        ->toBe(['2026-10-05' => 0, '2026-10-06' => 0, '2026-10-07' => 480]);

    // Queda en la auditoría (quién, antes y después).
    $activity = Activity::query()->where('subject_type', $absence->getMorphClass())->where('subject_id', $absence->id)->where('event', 'updated')->sole();
    expect($activity->causer_id)->toBe($this->manager->id)
        ->and($activity->attribute_changes['old']['status'])->toBe('requested')
        ->and($activity->attribute_changes['attributes']['status'])->toBe('approved');
});

test('una ausencia parcial aprobada resta solo sus horas de ese día', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Leave, '2026-10-05', '2026-10-05', 150));
    $this->service->approve($this->coManager, $absence);

    expect(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-10-05')))->toBe(330);
});

test('un responsable rechaza con un comentario obligatorio que recibe la persona', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));

    $this->actingAs($this->manager)
        ->post("/ausencias/{$absence->id}/rechazar", ['comment' => '  '])
        ->assertSessionHasErrors(['comment' => 'Explica por qué no se aprueba: el comentario es obligatorio.']);

    expect($absence->fresh()->status)->toBe(AbsenceStatus::Requested);

    $this->actingAs($this->manager)
        ->post("/ausencias/{$absence->id}/rechazar", ['comment' => 'Esa semana es la entrega de ACME'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena rechazada. Se lo hemos comunicado.');

    $absence->refresh();
    expect($absence->status)->toBe(AbsenceStatus::Rejected)
        ->and($absence->review_comment)->toBe('Esa semana es la entrega de ACME')
        ->and($absence->approved_by)->toBe($this->manager->id);

    Notification::assertSentTo($this->employee, AbsenceRejectedNotification::class, fn (AbsenceRejectedNotification $notification, array $channels) => $channels === ['database', 'mail']
        && $notification->title($this->employee) === 'Ausencia no aprobada: Vacaciones del 05/10/2026 al 09/10/2026'
        && $notification->body($this->employee) === 'Raúl: «Esa semana es la entrega de ACME»');

    // Una rechazada no resta capacidad y deja pedir otra en esas fechas.
    expect(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-10-05')))->toBe(480);
    ($this->request)()->assertSessionHasNoErrors();
});

test('solo se revisan las solicitudes pendientes', function () {
    $absence = Absence::factory()->for($this->employee)->approved()->create();

    $this->actingAs($this->manager)
        ->post("/ausencias/{$absence->id}/aprobar")
        ->assertSessionHasErrors(['absence' => 'Esta ausencia ya no está pendiente: está aprobada.']);

    $this->actingAs($this->manager)
        ->post("/ausencias/{$absence->id}/rechazar", ['comment' => 'No'])
        ->assertSessionHasErrors('absence');

    Notification::assertNothingSent();
});

test('nadie aprueba las suyas ni las de otro departamento; un admin, las de cualquiera', function () {
    $marketing = Department::factory()->create();
    $otherManager = User::factory()->departmentManager()->inDepartment($marketing)->create();
    $marketing->managers()->attach($otherManager);
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));

    $this->actingAs($otherManager)->post("/ausencias/{$absence->id}/aprobar")->assertForbidden();
    $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/aprobar")->assertForbidden();
    expect(fn () => $this->service->approve($otherManager, $absence))->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin)->post("/ausencias/{$absence->id}/aprobar")->assertSessionHasNoErrors();
    expect($absence->fresh()->status)->toBe(AbsenceStatus::Approved);
});

test('la persona retira una solicitud pendiente sin avisar a nadie', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    Notification::fake();

    $this->actingAs($this->employee)
        ->post("/ausencias/{$absence->id}/cancelar")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia cancelada.');

    expect($absence->fresh()->status)->toBe(AbsenceStatus::Cancelled);
    Notification::assertNothingSent();
});

test('la persona cancela una aprobada que aún no ha empezado y se avisa a quien la aprobó', function () {
    $absence = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $this->service->approve($this->manager, $absence);
    Notification::fake();

    $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/cancelar")->assertSessionHasNoErrors();

    expect($absence->fresh()->status)->toBe(AbsenceStatus::Cancelled)
        ->and(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-10-05')))->toBe(480);

    Notification::assertSentTo($this->manager, AbsenceCancelledNotification::class, fn (AbsenceCancelledNotification $notification) => $notification->title($this->manager) === 'Elena ha cancelado su ausencia: Vacaciones del 05/10/2026 al 09/10/2026'
        && $notification->url($this->manager) === '/ausencias/equipo');
    Notification::assertNotSentTo($this->employee, AbsenceCancelledNotification::class);
});

test('la persona no cancela una aprobada que ya ha empezado, ni una rechazada o cancelada', function (Closure $state) {
    $absence = Absence::factory()->for($this->employee)->state($state())->create();

    $this->actingAs($this->employee)->post("/ausencias/{$absence->id}/cancelar")->assertForbidden();
})->with([
    'aprobada en curso' => [fn () => ['status' => AbsenceStatus::Approved, 'start_date' => '2026-09-24', 'end_date' => '2026-09-30']],
    'aprobada pasada' => [fn () => ['status' => AbsenceStatus::Approved, 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']],
    'rechazada' => [fn () => ['status' => AbsenceStatus::Rejected]],
    'cancelada' => [fn () => ['status' => AbsenceStatus::Cancelled]],
]);

test('quien aprueba anula una aprobada, aunque haya empezado, y se avisa a la persona', function () {
    $absence = Absence::factory()->for($this->employee)->approved()->between('2026-09-21', '2026-09-30')->create(['type' => AbsenceType::Sick]);

    $this->actingAs($this->coManager)
        ->post("/ausencias/{$absence->id}/cancelar")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena anulada.');

    expect($absence->fresh()->status)->toBe(AbsenceStatus::Cancelled);
    Notification::assertSentTo($this->employee, AbsenceCancelledNotification::class, fn (AbsenceCancelledNotification $notification) => $notification->title($this->employee) === 'Bea ha anulado tu ausencia: Baja del 21/09/2026 al 30/09/2026'
        && $notification->url($this->employee) === '/ausencias');

    $activity = Activity::query()->where('subject_id', $absence->id)->where('subject_type', $absence->getMorphClass())->where('event', 'updated')->sole();
    expect($activity->causer_id)->toBe($this->coManager->id);
});

test('quien aprueba no anula una solicitud pendiente (la rechaza) y otro compañero no cancela nada', function () {
    $pending = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-05', '2026-10-09'));
    $approved = Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create();
    $colleague = User::factory()->employee()->inDepartment($this->design)->create();

    $this->actingAs($this->manager)->post("/ausencias/{$pending->id}/cancelar")->assertForbidden();
    $this->actingAs($colleague)->post("/ausencias/{$approved->id}/cancelar")->assertForbidden();

    expect($pending->fresh()->status)->toBe(AbsenceStatus::Requested)
        ->and($approved->fresh()->status)->toBe(AbsenceStatus::Approved);
});

test('un responsable registra una baja ya aprobada de alguien de su equipo y se le avisa', function () {
    $this->actingAs($this->manager)
        ->post('/ausencias/equipo', [
            'user_id' => $this->employee->id,
            'type' => 'sick',
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-25',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena registrada y aprobada: Baja del 22/09/2026 al 25/09/2026.');

    $absence = Absence::query()->sole();
    expect($absence->status)->toBe(AbsenceStatus::Approved)
        ->and($absence->approved_by)->toBe($this->manager->id)
        ->and($absence->user_id)->toBe($this->employee->id);

    Notification::assertSentTo($this->employee, AbsenceApprovedNotification::class, fn (AbsenceApprovedNotification $notification) => $notification->title($this->employee) === 'Raúl ha registrado tu ausencia: Baja del 22/09/2026 al 25/09/2026');
    expect(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-09-24')))->toBe(0);
});

test('solo se registran ausencias de personas del propio ámbito', function () {
    $outsider = User::factory()->employee()->inDepartment(Department::factory()->create())->create();
    $client = userWithRole('client');

    $payload = ['type' => 'sick', 'start_date' => '2026-09-22', 'end_date' => '2026-09-25'];

    $this->actingAs($this->manager)
        ->post('/ausencias/equipo', [...$payload, 'user_id' => $outsider->id])
        ->assertSessionHasErrors(['user_id' => 'No puedes registrar ausencias de esta persona.']);

    $this->actingAs($this->admin)
        ->post('/ausencias/equipo', [...$payload, 'user_id' => $client->id])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($this->admin)
        ->post('/ausencias/equipo', [...$payload, 'user_id' => $outsider->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->employee)
        ->post('/ausencias/equipo', [...$payload, 'user_id' => $this->employee->id])
        ->assertForbidden();

    expect(Absence::query()->count())->toBe(1);
});

test('no se registran ausencias de personas desactivadas', function () {
    $inactive = User::factory()->employee()->inDepartment($this->design)->inactive()->create();

    $this->actingAs($this->manager)
        ->post('/ausencias/equipo', ['user_id' => $inactive->id, 'type' => 'sick', 'start_date' => '2026-09-22', 'end_date' => '2026-09-25'])
        ->assertSessionHasErrors(['user_id' => 'La persona está desactivada: no se le pueden registrar ausencias.']);
});

test('no se solapa con otra ausencia solicitada o aprobada de la misma persona', function (array $existing, array $new, bool $overlaps) {
    Absence::factory()->for($this->employee)->create($existing);

    $response = ($this->request)($new);

    if ($overlaps) {
        $response->assertSessionHasErrors('start_date');
        expect(Absence::query()->count())->toBe(1);
    } else {
        $response->assertSessionHasNoErrors();
        expect(Absence::query()->count())->toBe(2);
    }
})->with([
    'solapa por el final' => [['start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'status' => AbsenceStatus::Requested], [], true],
    'dentro de una aprobada' => [['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => AbsenceStatus::Approved], [], true],
    'parcial el mismo día' => [['start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'partial_minutes' => 60, 'status' => AbsenceStatus::Approved], ['start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'partial_minutes' => 60], true],
    'justo después' => [['start_date' => '2026-10-01', 'end_date' => '2026-10-04', 'status' => AbsenceStatus::Approved], [], false],
    'con una rechazada' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'status' => AbsenceStatus::Rejected], [], false],
    'con una cancelada' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'status' => AbsenceStatus::Cancelled], [], false],
]);

test('el error de solape explica con qué ausencia coincide', function () {
    Absence::factory()->for($this->employee)->approved()->between('2026-10-01', '2026-10-05')->create();

    ($this->request)()->assertSessionHasErrors(['start_date' => 'Se solapa con otra ausencia tuya: Vacaciones del 01/10/2026 al 05/10/2026 (aprobada).']);

    $this->actingAs($this->manager)
        ->post('/ausencias/equipo', ['user_id' => $this->employee->id, 'type' => 'sick', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])
        ->assertSessionHasErrors(['start_date' => 'Se solapa con otra ausencia de Elena: Vacaciones del 01/10/2026 al 05/10/2026 (aprobada).']);
});

test('valida las fechas, la parte del día y la duración máxima', function (array $data, string $field, string $message) {
    ($this->request)($data)->assertSessionHasErrors([$field => $message]);

    expect(Absence::query()->count())->toBe(0);
})->with([
    'fin antes del inicio' => [['start_date' => '2026-10-09', 'end_date' => '2026-10-05'], 'end_date', 'La fecha de fin no puede ser anterior a la de inicio.'],
    'más de un año' => [['start_date' => '2026-10-05', 'end_date' => '2027-10-05'], 'end_date', 'Una ausencia puede durar como mucho un año. Divídela en varias.'],
    'parcial de varios días' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'partial_minutes' => 120], 'partial_minutes', 'Una ausencia de parte del día solo puede ser de un día.'],
    'parcial de 24 h' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'partial_minutes' => 1440], 'partial_minutes', 'Indica cuántas horas faltas: entre 0:01 y 23:59. Para el día entero, elige «Día completo».'],
    'demasiado lejos' => [['start_date' => '2029-01-01', 'end_date' => '2029-01-02'], 'start_date', 'Las fechas tienen que estar entre el 24/09/2024 y el 24/09/2028.'],
    'tipo desconocido' => [['type' => 'holiday'], 'type', 'El valor elegido en tipo no es válido.'],
    'fecha mal escrita' => [['start_date' => '05/10/2026'], 'start_date', 'El campo fecha de inicio debe tener el formato Y-m-d.'],
]);

test('una ausencia de exactamente un año sí se admite, y un día sin fin es de un día', function () {
    ($this->request)(['start_date' => '2026-10-05', 'end_date' => '2027-10-04'])->assertSessionHasNoErrors();
    ($this->request)(['start_date' => '2027-12-01', 'end_date' => null, 'partial_minutes' => 90])->assertSessionHasNoErrors();

    $partial = Absence::query()->where('start_date', '2027-12-01')->sole();
    expect($partial->end_date->toDateString())->toBe('2027-12-01')
        ->and($partial->partial_minutes)->toBe(90);
});

test('la capacidad de los informes descuenta las ausencias aprobadas y los festivos, no las pendientes', function () {
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
    $approved = $this->service->request($this->employee, new AbsenceData(AbsenceType::Vacation, '2026-10-13', '2026-10-14'));
    $this->service->approve($this->manager, $approved);
    $this->service->request($this->employee, new AbsenceData(AbsenceType::Training, '2026-10-15', '2026-10-15'));

    $week = app(Capacity::class)->forRange($this->employee, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18'));

    expect($week)->toBe([
        '2026-10-12' => 0,
        '2026-10-13' => 0,
        '2026-10-14' => 0,
        '2026-10-15' => 480,
        '2026-10-16' => 480,
        '2026-10-17' => 0,
        '2026-10-18' => 0,
    ])->and(array_sum($week))->toBe(960);
});
