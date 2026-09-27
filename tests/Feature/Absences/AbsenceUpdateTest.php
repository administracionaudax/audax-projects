<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Reports\ReportCache;
use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Department;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancelledNotification;
use App\Notifications\Absences\AbsenceUpdatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Modificar una ausencia aprobada (D-049): quien la aprueba la cambia (acortar una baja que termina
| antes, por ejemplo) sin anularla ni registrar otra: un solo aviso a la persona, las mismas reglas
| que al crearla y la auditoría. "Hoy" es el jueves 24/09/2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->manager = User::factory()->departmentManager()->inDepartment($this->design)->create(['name' => 'Raúl']);
    $this->design->managers()->attach($this->manager);
    $this->employee = User::factory()->employee()->inDepartment($this->design)->create(['name' => 'Elena']);
    $this->admin = User::factory()->admin()->create(['name' => 'Ana']);
    $this->service = app(AbsenceService::class);

    // Una baja en curso de Elena, registrada por Raúl.
    $this->sick = $this->service->register($this->manager, $this->employee, new AbsenceData(AbsenceType::Sick, '2026-09-21', '2026-10-09', notes: 'Gripe'));
    Notification::fake();

    $this->edit = fn (Absence $absence, array $data = [], ?User $user = null) => $this->actingAs($user ?? $this->manager)->put("/ausencias/{$absence->id}", [
        'type' => $absence->type->value,
        'start_date' => $absence->start_date->toDateString(),
        'end_date' => $absence->end_date->toDateString(),
        'partial_minutes' => $absence->partial_minutes,
        'notes' => $absence->notes,
        ...$data,
    ]);
});

test('quien aprueba acorta una baja en curso: un solo aviso a la persona, la capacidad vuelve y queda en la auditoría', function () {
    $version = ReportCache::version();

    ($this->edit)($this->sick, ['end_date' => '2026-09-30'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena modificada: Baja del 21/09/2026 al 30/09/2026. Se lo hemos comunicado.');

    $sick = $this->sick->fresh();
    expect($sick->status)->toBe(AbsenceStatus::Approved)
        ->and($sick->start_date->toDateString())->toBe('2026-09-21')
        ->and($sick->end_date->toDateString())->toBe('2026-09-30')
        ->and($sick->notes)->toBe('Gripe')
        ->and($sick->approved_by)->toBe($this->manager->id)
        ->and(ReportCache::version())->toBeGreaterThan($version);

    // Los días que deja libres vuelven a contar como jornada.
    expect(app(Capacity::class)->forRange($this->employee, CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-10-01')))
        ->toBe(['2026-09-30' => 0, '2026-10-01' => 480]);

    Notification::assertSentTo($this->employee, AbsenceUpdatedNotification::class, fn (AbsenceUpdatedNotification $notification, array $channels) => $channels === ['database', 'mail']
        && $notification->kind() === 'absence.updated'
        && $notification->title($this->employee) === 'Raúl ha modificado tu ausencia: Baja del 21/09/2026 al 30/09/2026'
        && $notification->body($this->employee) === 'Antes: Baja del 21/09/2026 al 09/10/2026.'
        && $notification->url($this->employee) === '/ausencias'
        && $notification->icon() === 'calendar-clock');
    Notification::assertSentTimes(AbsenceUpdatedNotification::class, 1);
    Notification::assertNotSentTo($this->employee, AbsenceCancelledNotification::class);
    Notification::assertNotSentTo($this->employee, AbsenceApprovedNotification::class);

    $activity = Activity::query()->where('subject_type', $sick->getMorphClass())->where('subject_id', $sick->id)->where('event', 'updated')->sole();
    expect($activity->causer_id)->toBe($this->manager->id)
        ->and($activity->attribute_changes['old']['end_date'])->toStartWith('2026-10-09')
        ->and($activity->attribute_changes['attributes']['end_date'])->toStartWith('2026-09-30');
});

test('un admin cambia el tipo y la deja en parte de un día', function () {
    $vacation = Absence::factory()->for($this->employee)->approved()->between('2026-11-02', '2026-11-03')->create(['type' => AbsenceType::Vacation]);

    ($this->edit)($vacation, ['type' => 'leave', 'end_date' => '2026-11-02', 'partial_minutes' => 150, 'notes' => 'Médico'], $this->admin)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ausencia de Elena modificada: Permiso el 02/11/2026 (2:30). Se lo hemos comunicado.');

    $vacation->refresh();
    expect($vacation->type)->toBe(AbsenceType::Leave)
        ->and($vacation->end_date->toDateString())->toBe('2026-11-02')
        ->and($vacation->partial_minutes)->toBe(150)
        ->and($vacation->notes)->toBe('Médico')
        ->and($vacation->approved_by)->toBe($this->admin->id)
        ->and(app(Capacity::class)->onDate($this->employee, CarbonImmutable::parse('2026-11-02')))->toBe(330);

    Notification::assertSentTo($this->employee, AbsenceUpdatedNotification::class, fn (AbsenceUpdatedNotification $notification) => $notification->title($this->employee) === 'Ana ha modificado tu ausencia: Permiso el 02/11/2026 (2:30)'
        && $notification->body($this->employee) === 'Antes: Vacaciones del 02/11/2026 al 03/11/2026.');
});

test('sin cambios no se guarda, no se avisa y no se invalida la caché', function () {
    $version = ReportCache::version();
    $this->travel(5)->minutes();

    ($this->edit)($this->sick)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', 'La ausencia de Elena no tenía cambios.');

    expect($this->sick->fresh()->updated_at->equalTo($this->sick->updated_at))->toBeTrue()
        ->and(ReportCache::version())->toBe($version)
        ->and(Activity::query()->where('subject_id', $this->sick->id)->where('event', 'updated')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('al modificarla valen las mismas reglas, sin contar la propia ausencia como solape', function (array $data, string $field, string $message) {
    Absence::factory()->for($this->employee)->approved()->between('2026-10-13', '2026-10-16')->create(['type' => AbsenceType::Vacation]);

    ($this->edit)($this->sick, $data)->assertSessionHasErrors([$field => $message]);

    expect($this->sick->fresh()->end_date->toDateString())->toBe('2026-10-09');
    Notification::assertNothingSent();
})->with([
    'se solapa con otra' => [['end_date' => '2026-10-14'], 'start_date', 'Se solapa con otra ausencia de Elena: Vacaciones del 13/10/2026 al 16/10/2026 (aprobada).'],
    'fin antes del inicio' => [['end_date' => '2026-09-20'], 'end_date', 'La fecha de fin no puede ser anterior a la de inicio.'],
    'parte del día en varios días' => [['partial_minutes' => 120], 'partial_minutes', 'Una ausencia de parte del día solo puede ser de un día.'],
    'más de un año' => [['end_date' => '2027-09-21'], 'end_date', 'Una ausencia puede durar como mucho un año. Divídela en varias.'],
    'sin tipo' => [['type' => ''], 'type', 'El campo tipo es obligatorio.'],
]);

test('solo quien aprueba modifica las aprobadas de otras personas', function (Closure $actor, Closure $absence) {
    $target = $absence->call($this);

    ($this->edit)($target, ['end_date' => $target->start_date->toDateString()], $actor->call($this))->assertForbidden();

    expect($target->fresh()->end_date->toDateString())->toBe($target->end_date->toDateString());
    Notification::assertNothingSent();
})->with([
    'la propia persona' => [fn () => $this->employee, fn () => $this->sick],
    'un compañero' => [fn () => User::factory()->employee()->inDepartment($this->design)->create(), fn () => $this->sick],
    'el responsable de otro departamento' => [function () {
        $other = Department::factory()->create();
        $manager = User::factory()->departmentManager()->inDepartment($other)->create();
        $other->managers()->attach($manager);

        return $manager;
    }, fn () => $this->sick],
    'un responsable, la suya (aprobada sola)' => [fn () => $this->manager, fn () => Absence::factory()->for($this->manager)->approved()->between('2026-11-02', '2026-11-03')->create()],
    'una solicitud pendiente (se aprueba o se rechaza)' => [fn () => $this->manager, fn () => Absence::factory()->for($this->employee)->between('2026-11-02', '2026-11-03')->create()],
    'una rechazada' => [fn () => $this->manager, fn () => Absence::factory()->for($this->employee)->between('2026-11-02', '2026-11-03')->create(['status' => AbsenceStatus::Rejected])],
    'una cancelada' => [fn () => $this->admin, fn () => Absence::factory()->for($this->employee)->between('2026-11-02', '2026-11-03')->create(['status' => AbsenceStatus::Cancelled])],
]);

test('AbsenceService también lo autoriza y vuelve a mirar el estado dentro de la transacción', function () {
    $data = new AbsenceData(AbsenceType::Sick, '2026-09-21', '2026-09-25');

    expect(fn () => $this->service->update($this->employee, $this->sick, $data))->toThrow(AuthorizationException::class);

    // Otra persona la anula mientras tanto: ya no se puede modificar.
    $stale = $this->sick->fresh();
    Absence::query()->whereKey($stale->id)->update(['status' => AbsenceStatus::Cancelled->value]);

    expect(fn () => $this->service->update($this->manager, $stale, $data))
        ->toThrow(ValidationException::class, 'Esta ausencia ya no se puede modificar: está cancelada.');
    expect($stale->fresh()->end_date->toDateString())->toBe('2026-10-09');
});

test('«Ausencias del equipo» ofrece modificar las aprobadas de otras personas, no las propias', function () {
    $own = Absence::factory()->for($this->manager)->approved()->between('2026-11-02', '2026-11-03')->create();

    $this->actingAs($this->manager)
        ->get('/ausencias/equipo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('upcoming', 2)
            ->where('upcoming.0.id', $this->sick->id)
            ->where('upcoming.0.can', ['cancel' => true, 'review' => false, 'update' => true])
            ->where('upcoming.1.id', $own->id)
            ->where('upcoming.1.can.update', false));

    // En «Mis ausencias», la persona no modifica las suyas.
    $this->actingAs($this->employee)
        ->get('/ausencias')
        ->assertInertia(fn (Assert $page) => $page->where('absences.0.can.update', false));
});
