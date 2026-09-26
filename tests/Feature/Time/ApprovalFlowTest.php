<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\Time\TimesheetApproved;
use App\Notifications\Time\TimesheetReturned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/*
| Flujo de la semana (SPEC §7, D-020, D-024, D-034): enviar, aprobar, devolver, retirar y reabrir,
| con las instantáneas de tarifa y coste. "Hoy" es el viernes 25/09/2026 (semana 2026-W39).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->department = Department::factory()->create(['name' => 'Diseño']);
    $this->head = User::factory()->departmentManager()->inDepartment($this->department)->create(['name' => 'Lucía Responsable']);
    $this->department->managers()->attach($this->head);
    $this->employee = User::factory()->employee()->inDepartment($this->department)->create(['name' => 'Pablo Empleado', 'hourly_cost' => '25.50', 'default_hourly_rate' => '40.00']);
    $this->admin = User::factory()->admin()->create();

    $this->project = Project::factory()->create(['client_id' => Client::factory()->create(['default_hourly_rate' => null])->id]);
    $this->project->addMember($this->employee);
    $this->task = Task::factory()->create(['project_id' => $this->project->id]);

    $this->log = fn (User $user, string $date, int $minutes = 60, ?Task $task = null): TimeEntry => TimeEntry::factory()
        ->forTask($task ?? $this->task)->on($date)->minutes($minutes)->create(['user_id' => $user->id]);

    $this->submit = fn (User $user, string $week = '2026-W39') => $this->actingAs($user)->post('/horas/semana/enviar', ['week' => $week]);

    $this->period = fn (User $user, string $weekStart = '2026-09-21'): ?TimesheetPeriod => TimesheetPeriod::query()
        ->where('user_id', $user->id)->where('week_start', $weekStart)->first();
});

it('un empleado envía su semana: entradas y semana pasan a enviadas', function () {
    $monday = ($this->log)($this->employee, '2026-09-21');
    $friday = ($this->log)($this->employee, '2026-09-25', 120);
    $previousWeek = ($this->log)($this->employee, '2026-09-18');

    ($this->submit)($this->employee)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Semana enviada para su aprobación.');

    $period = ($this->period)($this->employee);
    expect($period->status)->toBe(TimesheetStatus::Submitted)
        ->and($period->submitted_at)->not->toBeNull()
        ->and($monday->fresh()->status)->toBe(TimeEntryStatus::Submitted)
        ->and($friday->fresh()->status)->toBe(TimeEntryStatus::Submitted)
        ->and($previousWeek->fresh()->status)->toBe(TimeEntryStatus::Draft);

    expect(Activity::query()->where('subject_type', $period->getMorphClass())->where('subject_id', $period->id)->where('event', 'submitted')->exists())->toBeTrue();
    Notification::assertNothingSent();
});

it('una semana sin entradas también se puede enviar', function () {
    ($this->submit)($this->employee)->assertSessionHasNoErrors();

    expect(($this->period)($this->employee)->status)->toBe(TimesheetStatus::Submitted);
});

it('no se envía una semana que aún no ha empezado ni con un formato no válido', function () {
    ($this->submit)($this->employee, '2026-W40')->assertSessionHasErrors(['week' => 'Esta semana aún no ha empezado: no se puede enviar.']);
    ($this->submit)($this->employee, '2026-39')->assertSessionHasErrors('week');

    expect(TimesheetPeriod::query()->count())->toBe(0);
});

it('no se reenvía una semana ya enviada', function () {
    ($this->submit)($this->employee);
    ($this->submit)($this->employee)->assertForbidden();
});

it('las semanas de responsables y admins se aprueban solas al enviarlas, con instantáneas (D-020)', function (string $who) {
    $user = $who === 'responsable' ? $this->head : $this->admin;
    $user->update(['hourly_cost' => '30.00', 'default_hourly_rate' => '50.00']);
    $this->project->addMember($user);
    $entry = ($this->log)($user, '2026-09-22');

    ($this->submit)($user)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Semana enviada y aprobada.');

    $period = ($this->period)($user);
    $entry->refresh();
    expect($period->status)->toBe(TimesheetStatus::Approved)
        ->and($period->reviewed_by)->toBeNull()
        ->and($period->reviewed_at)->not->toBeNull()
        ->and($entry->status)->toBe(TimeEntryStatus::Approved)
        ->and($entry->approved_at)->not->toBeNull()
        ->and($entry->hourly_rate_snapshot)->toBe('50.00')
        ->and($entry->hourly_cost_snapshot)->toBe('30.00');

    Notification::assertNothingSent();
})->with(['responsable', 'admin']);

it('sin aprobación obligatoria, todo se aprueba al enviar', function () {
    Setting::set('require_timesheet_approval', false);
    $entry = ($this->log)($this->employee, '2026-09-22');

    ($this->submit)($this->employee)->assertSessionHasNoErrors();

    expect(($this->period)($this->employee)->status)->toBe(TimesheetStatus::Approved)
        ->and($entry->fresh()->status)->toBe(TimeEntryStatus::Approved);
});

it('el responsable del departamento aprueba: congela tarifa y coste y avisa al empleado', function () {
    $entry = ($this->log)($this->employee, '2026-09-22', 90);
    ($this->submit)($this->employee);
    $period = ($this->period)($this->employee);

    $this->actingAs($this->head)
        ->post("/horas/aprobaciones/{$period->id}/aprobar")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Semana de Pablo Empleado aprobada.');

    $period->refresh();
    $entry->refresh();
    expect($period->status)->toBe(TimesheetStatus::Approved)
        ->and($period->reviewed_by)->toBe($this->head->id)
        ->and($entry->status)->toBe(TimeEntryStatus::Approved)
        ->and($entry->approved_by)->toBe($this->head->id)
        ->and($entry->hourly_rate_snapshot)->toBe('40.00')
        ->and($entry->hourly_cost_snapshot)->toBe('25.50');

    Notification::assertSentTo($this->employee, TimesheetApproved::class, function (TimesheetApproved $notification) {
        $data = $notification->toArray($this->employee);

        return $data['kind'] === 'time.approved'
            && $data['url'] === '/horas?semana=2026-W39'
            && $data['title'] === 'Tus horas de la semana del 21/09/2026 están aprobadas';
    });
});

it('la tarifa congelada sigue la prioridad bolsa > proyecto > cliente > usuario', function () {
    $client = Client::factory()->create(['default_hourly_rate' => '55.00']);
    $project = Project::factory()->hourBank()->create(['client_id' => $client->id, 'hourly_rate' => '60.00']);
    $project->addMember($this->employee);

    $withBankRate = HourBank::factory()->create(['project_id' => $project->id, 'hourly_rate' => '70.00']);
    $withoutBankRate = HourBank::factory()->create(['project_id' => $project->id, 'hourly_rate' => null]);
    $bankEntry = ($this->log)($this->employee, '2026-09-21', 60, Task::factory()->inBank($withBankRate)->create());
    $projectEntry = ($this->log)($this->employee, '2026-09-21', 60, Task::factory()->inBank($withoutBankRate)->create());

    $clientProject = Project::factory()->create(['client_id' => $client->id, 'hourly_rate' => null]);
    $clientProject->addMember($this->employee);
    $clientEntry = ($this->log)($this->employee, '2026-09-22', 60, Task::factory()->create(['project_id' => $clientProject->id]));

    $userEntry = ($this->log)($this->employee, '2026-09-23'); // proyecto y cliente sin tarifa

    $internal = Project::factory()->internal()->create();
    $this->employee->update(['default_hourly_rate' => null]);
    $noRateEntry = ($this->log)($this->employee, '2026-09-24', 60, Task::factory()->create(['project_id' => $internal->id]));

    ($this->submit)($this->employee);
    $this->actingAs($this->head)->post('/horas/aprobaciones/'.($this->period)($this->employee)->id.'/aprobar')->assertSessionHasNoErrors();

    expect($bankEntry->fresh()->hourly_rate_snapshot)->toBe('70.00')
        ->and($projectEntry->fresh()->hourly_rate_snapshot)->toBe('60.00')
        ->and($clientEntry->fresh()->hourly_rate_snapshot)->toBe('55.00')
        ->and($userEntry->fresh()->hourly_rate_snapshot)->toBeNull()
        ->and($noRateEntry->fresh()->hourly_rate_snapshot)->toBeNull();

    // Con tarifa de usuario (y sin las demás), la del usuario.
    $this->employee->update(['default_hourly_rate' => '45.00']);
    $reopened = ($this->period)($this->employee);
    $this->actingAs($this->admin)->post("/horas/semanas/{$reopened->id}/reabrir")->assertSessionHasNoErrors();
    ($this->submit)($this->employee);
    $this->actingAs($this->head)->post("/horas/aprobaciones/{$reopened->id}/aprobar");

    expect($userEntry->fresh()->hourly_rate_snapshot)->toBe('45.00');
});

it('devolver exige comentario, vuelve las entradas a borrador y avisa con el comentario', function () {
    $entry = ($this->log)($this->employee, '2026-09-22');
    ($this->submit)($this->employee);
    $period = ($this->period)($this->employee);

    $this->actingAs($this->head)
        ->post("/horas/aprobaciones/{$period->id}/devolver", ['comment' => '  '])
        ->assertSessionHasErrors(['comment' => 'Explica qué hay que corregir: el comentario es obligatorio.']);

    $this->actingAs($this->head)
        ->post("/horas/aprobaciones/{$period->id}/devolver", ['comment' => 'Falta el viernes'])
        ->assertSessionHasNoErrors();

    $period->refresh();
    expect($period->status)->toBe(TimesheetStatus::Returned)
        ->and($period->review_comment)->toBe('Falta el viernes')
        ->and($entry->fresh()->status)->toBe(TimeEntryStatus::Draft);

    Notification::assertSentTo($this->employee, TimesheetReturned::class, fn (TimesheetReturned $notification) => $notification->toArray($this->employee)['body'] === 'Lucía Responsable: «Falta el viernes»');

    // Devuelta vuelve a ser editable y se puede reenviar.
    $this->actingAs($this->employee)
        ->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '2026-09-25', 'minutes' => 60])
        ->assertSessionHasNoErrors();
    ($this->submit)($this->employee)->assertSessionHasNoErrors();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Submitted)
        ->and($period->fresh()->review_comment)->toBeNull();
});

it('el dueño retira una semana enviada mientras no se ha revisado', function () {
    $entry = ($this->log)($this->employee, '2026-09-22');
    ($this->submit)($this->employee);

    $this->actingAs($this->employee)
        ->post('/horas/semana/retirar', ['week' => '2026-W39'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Semana retirada: vuelves a poder editarla.');

    expect(($this->period)($this->employee)->status)->toBe(TimesheetStatus::Open)
        ->and($entry->fresh()->status)->toBe(TimeEntryStatus::Draft);

    // Una aprobada ya no se retira.
    ($this->submit)($this->employee);
    $this->actingAs($this->head)->post('/horas/aprobaciones/'.($this->period)($this->employee)->id.'/aprobar');
    $this->actingAs($this->employee)->post('/horas/semana/retirar', ['week' => '2026-W39'])->assertForbidden();
});

it('reabrir una aprobada la deja abierta y borra las instantáneas; las bloqueadas no cambian', function () {
    $entry = ($this->log)($this->employee, '2026-09-22');
    $locked = TimeEntry::factory()->forTask($this->task)->on('2026-09-21')->status(TimeEntryStatus::Locked)
        ->create(['user_id' => $this->employee->id, 'hourly_rate_snapshot' => '40.00']);
    ($this->submit)($this->employee);
    $period = ($this->period)($this->employee);
    $this->actingAs($this->head)->post("/horas/aprobaciones/{$period->id}/aprobar");
    expect($entry->fresh()->hourly_cost_snapshot)->toBe('25.50');

    $this->actingAs($this->head)
        ->post("/horas/semanas/{$period->id}/reabrir")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Semana reabierta: sus horas vuelven a ser editables.');

    $entry->refresh();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Open)
        ->and($entry->status)->toBe(TimeEntryStatus::Draft)
        ->and($entry->hourly_rate_snapshot)->toBeNull()
        ->and($entry->hourly_cost_snapshot)->toBeNull()
        ->and($entry->approved_by)->toBeNull()
        ->and($locked->fresh()->status)->toBe(TimeEntryStatus::Locked)
        ->and($locked->fresh()->hourly_rate_snapshot)->toBe('40.00');

    expect(Activity::query()->where('subject_id', $period->id)->where('event', 'reopened')->where('causer_id', $this->head->id)->exists())->toBeTrue();
});

it('una semana bloqueada solo la reabre un admin; el propio empleado nunca', function () {
    $period = TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Locked)->create();

    $this->actingAs($this->employee)->post("/horas/semanas/{$period->id}/reabrir")->assertForbidden();
    $this->actingAs($this->head)->post("/horas/semanas/{$period->id}/reabrir")->assertForbidden();
    $this->actingAs($this->admin)->post("/horas/semanas/{$period->id}/reabrir")->assertSessionHasNoErrors();

    expect($period->fresh()->status)->toBe(TimesheetStatus::Open);
});

it('solo se revisan semanas enviadas, y nunca las propias', function () {
    $period = TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Open)->create();

    $this->actingAs($this->head)
        ->post("/horas/aprobaciones/{$period->id}/aprobar")
        ->assertSessionHasErrors('week');

    $own = TimesheetPeriod::factory()->for($this->head)->week('2026-09-14')->status(TimesheetStatus::Submitted)->create();
    $this->actingAs($this->head)->post("/horas/aprobaciones/{$own->id}/aprobar")->assertForbidden();
});

it('quién aprueba: el responsable de su departamento o un admin; otro responsable no (D-020, D-024)', function () {
    ($this->submit)($this->employee);
    $period = ($this->period)($this->employee);

    $otherHead = User::factory()->departmentManager()->create();
    Department::factory()->create(['name' => 'Marketing'])->managers()->attach($otherHead);
    $this->actingAs($otherHead)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertForbidden();

    $colleague = User::factory()->employee()->inDepartment($this->department)->create();
    $this->actingAs($colleague)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertForbidden();

    // Un segundo responsable del mismo departamento también puede (D-024).
    $coHead = User::factory()->departmentManager()->create();
    $this->department->managers()->attach($coHead);
    $this->actingAs($coHead)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Approved);
});

it('sin departamento, o con un departamento sin responsables, aprueba un admin', function (string $case) {
    $user = $case === 'sin departamento'
        ? User::factory()->employee()->create()
        : User::factory()->employee()->inDepartment(Department::factory()->create(['name' => 'Cuentas']))->create();

    ($this->submit)($user)->assertSessionHasNoErrors();
    $period = ($this->period)($user);
    expect($period->status)->toBe(TimesheetStatus::Submitted);

    $this->actingAs($this->head)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertForbidden();

    $this->actingAs($this->admin)
        ->get('/horas/aprobaciones')
        ->assertInertia(fn ($page) => $page->where('pending.0.period.id', $period->id));

    $this->actingAs($this->admin)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Approved);
})->with(['sin departamento', 'departamento sin responsables']);

it('aprueba varias semanas a la vez; si alguna no se puede, no aprueba ninguna', function () {
    $second = User::factory()->employee()->inDepartment($this->department)->create();
    ($this->submit)($this->employee);
    ($this->submit)($second);
    $first = ($this->period)($this->employee);
    $other = ($this->period)($second);
    $open = TimesheetPeriod::factory()->for($second)->week('2026-09-14')->status(TimesheetStatus::Open)->create();

    $this->actingAs($this->head)
        ->post('/horas/aprobaciones/aprobar', ['periods' => [$first->id, $open->id]])
        ->assertSessionHasErrors('periods');
    expect($first->fresh()->status)->toBe(TimesheetStatus::Submitted);

    $this->actingAs($this->head)
        ->post('/horas/aprobaciones/aprobar', ['periods' => [$first->id, $other->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Se han aprobado 2 semanas.');

    expect($first->fresh()->status)->toBe(TimesheetStatus::Approved)
        ->and($other->fresh()->status)->toBe(TimesheetStatus::Approved);
    Notification::assertSentTimes(TimesheetApproved::class, 2);
});

it('la página de aprobaciones lista las semanas enviadas de su equipo con totales por día, sin N+1', function () {
    $people = User::factory()->count(3)->employee()->inDepartment($this->department)->create();
    foreach ($people as $index => $person) {
        $this->project->addMember($person);
        ($this->log)($person, '2026-09-21', 60 * ($index + 1));
        ($this->log)($person, '2026-09-23', 30);
        ($this->submit)($person);
    }
    // De otro departamento: no le corresponde.
    $outsider = User::factory()->employee()->inDepartment(Department::factory()->create(['name' => 'Marketing']))->create();
    ($this->submit)($outsider);

    $this->actingAs($this->head)
        ->get('/horas/aprobaciones')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('time/approvals', false)
            ->has('pending', 3)
            ->where('pending.0.days.2026-09-21', 60)
            ->where('pending.0.days.2026-09-23', 30)
            ->where('pending.0.total', 90)
            ->where('pending.0.capacity', 2400)
            ->where('pending.0.department', 'Diseño')
            ->has('pending.0.entries', 2)
            ->where('pending.2.total', 210));

    // El admin ve también la del otro departamento.
    $this->actingAs($this->admin)
        ->get('/horas/aprobaciones')
        ->assertInertia(fn ($page) => $page->has('pending', 4));
});

it('la página de aprobaciones hace las mismas consultas con 2 semanas pendientes que con 12, y solo carga sus entradas', function () {
    // Personas del departamento con horario propio, semanas enviadas y otras ya revisadas (histórico).
    $team = function (int $people, array $pendingWeeks, array $historyWeeks): void {
        foreach (User::factory()->count($people)->employee()->inDepartment($this->department)->create() as $person) {
            WorkSchedule::factory()->intensive()->create(['user_id' => $person->id]);
            $this->project->addMember($person);

            foreach ($pendingWeeks as $monday) {
                ($this->log)($person, $monday, 60);
                TimesheetPeriod::factory()->for($person)->week($monday)->status(TimesheetStatus::Submitted)->create();
            }

            foreach ($historyWeeks as $monday) {
                ($this->log)($person, $monday, 30);
                TimesheetPeriod::factory()->for($person)->week($monday)->status(TimesheetStatus::Approved)
                    ->create(['reviewed_by' => $this->head->id, 'reviewed_at' => now()]);
            }
        }
    };

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->head)->get('/horas/aprobaciones')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $team(1, ['2026-09-14', '2026-09-21'], ['2026-09-07']);
    $queries(); // Calienta lo que se carga una vez por proceso (ajustes, permisos…).
    $few = $queries();

    // En total, 12 semanas pendientes (6 personas × 2, una de ellas muy antigua) y 7 en el histórico.
    $team(4, ['2026-09-14', '2026-09-21'], ['2026-09-07']);
    $team(1, ['2026-07-06', '2026-09-21'], ['2026-08-03', '2026-08-10']);
    // Entre la semana antigua y las recientes hay horas que no son de ninguna semana de la página.
    $stray = User::query()->latest('id')->firstOrFail();
    foreach (['2026-07-20', '2026-08-17', '2026-08-31'] as $date) {
        ($this->log)($stray, $date, 45);
    }

    $retrieved = 0;
    Event::listen('eloquent.retrieved: '.TimeEntry::class, function () use (&$retrieved): void {
        $retrieved++;
    });

    expect($queries())->toBe($few);
    // Solo se cargan las entradas de las semanas pendientes (una por semana); el histórico se suma en la base de datos.
    expect($retrieved)->toBe(12);

    $this->actingAs($this->head)
        ->get('/horas/aprobaciones')
        ->assertInertia(fn ($page) => $page
            ->has('pending', 12)
            ->where('pending.0.period.week_start', '2026-07-06')
            ->where('pending.0.capacity', 34 * 60)
            ->where('pending.0.total', 60)
            ->has('pending.0.entries', 1)
            ->has('history', 7)
            ->where('history.0.total', 30));
});
