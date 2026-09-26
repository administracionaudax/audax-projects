<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Bloqueo de horas al facturar (SPEC §7, D-034): solo admin, por cliente o proyecto y rango.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->admin = User::factory()->admin()->create();
    $this->employee = User::factory()->employee()->create();
    $this->client = Client::factory()->create(['name' => 'ACME']);
    $this->web = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ACME-WEB']);
    $this->seo = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ACME-SEO']);
    $this->other = Project::factory()->create(['code' => 'OTRO']);

    $this->entry = fn (Project $project, string $date, TimeEntryStatus $status, int $minutes = 60): TimeEntry => TimeEntry::factory()
        ->forTask(Task::factory()->create(['project_id' => $project->id]))
        ->on($date)->minutes($minutes)->status($status)
        ->create(['user_id' => $this->employee->id]);
});

it('la vista previa cuenta las aprobadas que se bloquearán y avisa de las no aprobadas del rango', function () {
    ($this->entry)($this->web, '2026-09-01', TimeEntryStatus::Approved, 120);
    ($this->entry)($this->seo, '2026-09-10', TimeEntryStatus::Approved, 30);
    ($this->entry)($this->web, '2026-09-11', TimeEntryStatus::Submitted, 45);
    ($this->entry)($this->web, '2026-09-12', TimeEntryStatus::Draft, 15);
    ($this->entry)($this->web, '2026-10-01', TimeEntryStatus::Approved); // fuera del rango
    ($this->entry)($this->other, '2026-09-05', TimeEntryStatus::Approved); // otro cliente

    $this->actingAs($this->admin)
        ->get("/horas/bloqueo/vista-previa?client_id={$this->client->id}&date_from=2026-09-01&date_to=2026-09-30&reference=F-2026-101")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('time/locks', false)
            ->where('preview.summary.lockable', ['count' => 2, 'minutes' => 150])
            ->where('preview.summary.pending.submitted', ['count' => 1, 'minutes' => 45])
            ->where('preview.summary.pending.draft', ['count' => 1, 'minutes' => 15])
            ->has('preview.entries', 2)
            ->where('filters.reference', 'F-2026-101'));

    // Por proyecto, solo ese proyecto.
    $this->actingAs($this->admin)
        ->get("/horas/bloqueo/vista-previa?project_id={$this->seo->id}&date_from=2026-09-01&date_to=2026-09-30")
        ->assertInertia(fn (Assert $page) => $page->where('preview.summary.lockable', ['count' => 1, 'minutes' => 30]));
});

it('exige un cliente o un proyecto (no los dos) y un rango válido', function () {
    $this->actingAs($this->admin)
        ->from('/horas/bloqueo')
        ->get('/horas/bloqueo/vista-previa?date_from=2026-09-01&date_to=2026-09-30')
        ->assertRedirect('/horas/bloqueo')
        ->assertSessionHasErrors(['client_id' => 'Elige un cliente o un proyecto.']);

    $this->actingAs($this->admin)
        ->post('/horas/bloqueo', ['client_id' => $this->client->id, 'project_id' => $this->web->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'])
        ->assertSessionHasErrors(['client_id' => 'Elige un cliente o un proyecto, no los dos.']);

    $this->actingAs($this->admin)
        ->post('/horas/bloqueo', ['client_id' => $this->client->id, 'date_from' => '2026-09-30', 'date_to' => '2026-09-01'])
        ->assertSessionHasErrors('date_to');

    $this->actingAs($this->admin)
        ->post('/horas/bloqueo', ['client_id' => $this->client->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'])
        ->assertSessionHasErrors(['date_from' => 'No hay horas aprobadas que bloquear en ese rango.']);

    expect(TimeEntryLock::query()->count())->toBe(0);
});

it('bloquea las aprobadas del rango, deja las demás y pasa a bloqueadas las semanas que quedan enteras', function () {
    // Semana del 07/09: todo aprobado → se bloquea entera.
    $week1a = ($this->entry)($this->web, '2026-09-07', TimeEntryStatus::Approved);
    $week1b = ($this->entry)($this->seo, '2026-09-09', TimeEntryStatus::Approved);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-07')->status(TimesheetStatus::Approved)->create();
    // Semana del 14/09: una aprobada del cliente y otra de otro cliente → la semana sigue aprobada.
    $week2 = ($this->entry)($this->web, '2026-09-14', TimeEntryStatus::Approved);
    $foreign = ($this->entry)($this->other, '2026-09-15', TimeEntryStatus::Approved);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-14')->status(TimesheetStatus::Approved)->create();
    // Enviada: no se toca.
    $submitted = ($this->entry)($this->web, '2026-09-22', TimeEntryStatus::Submitted);
    $bankBefore = HourBank::query()->count();

    $this->actingAs($this->admin)
        ->post('/horas/bloqueo', ['client_id' => $this->client->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'reference' => 'F-2026-101'])
        ->assertRedirect('/horas/bloqueo')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Se han bloqueado 3 entradas.');

    $lock = TimeEntryLock::query()->sole();
    expect($lock->client_id)->toBe($this->client->id)
        ->and($lock->project_id)->toBeNull()
        ->and($lock->entries_count)->toBe(3)
        ->and($lock->reference)->toBe('F-2026-101')
        ->and($lock->locked_by)->toBe($this->admin->id);

    foreach ([$week1a, $week1b, $week2] as $entry) {
        $entry->refresh();
        expect($entry->status)->toBe(TimeEntryStatus::Locked)
            ->and($entry->locked_at)->not->toBeNull()
            ->and($entry->time_entry_lock_id)->toBe($lock->id);
    }
    expect($foreign->fresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and($submitted->fresh()->status)->toBe(TimeEntryStatus::Submitted)
        ->and(HourBank::query()->count())->toBe($bankBefore);

    $periods = TimesheetPeriod::query()->orderBy('week_start')->pluck('status', 'week_start')->map(fn ($status) => $status->value)->all();
    expect($periods)->toBe(['2026-09-07' => 'locked', '2026-09-14' => 'approved']);

    $activity = Activity::query()->where('subject_type', $lock->getMorphClass())->where('event', 'locked')->sole();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['entries'])->toBe(3)
        ->and($activity->properties['reference'])->toBe('F-2026-101');
});

it('desbloquear devuelve las entradas a aprobadas y sus semanas a aprobadas', function () {
    $entry = ($this->entry)($this->web, '2026-09-07', TimeEntryStatus::Approved);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-07')->status(TimesheetStatus::Approved)->create();
    $this->actingAs($this->admin)->post('/horas/bloqueo', ['project_id' => $this->web->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
    $lock = TimeEntryLock::query()->sole();

    $this->actingAs($this->admin)
        ->delete("/horas/bloqueo/{$lock->id}")
        ->assertRedirect('/horas/bloqueo')
        ->assertInertiaFlash('toast.message', 'Se ha desbloqueado 1 entrada.');

    $entry->refresh();
    $lock->refresh();
    expect($entry->status)->toBe(TimeEntryStatus::Approved)
        ->and($entry->locked_at)->toBeNull()
        ->and($entry->time_entry_lock_id)->toBeNull()
        ->and($lock->unlocked_at)->not->toBeNull()
        ->and($lock->unlocked_by)->toBe($this->admin->id)
        ->and(TimesheetPeriod::query()->sole()->status)->toBe(TimesheetStatus::Approved);

    // Deshacerlo dos veces, no.
    $this->actingAs($this->admin)->delete("/horas/bloqueo/{$lock->id}")->assertSessionHasErrors('lock');
});

it('bloquear, reabrir, desbloquear y editar: la entrada desbloqueada vuelve a borrador y se aprueba de nuevo', function () {
    $department = Department::factory()->create();
    $head = User::factory()->departmentManager()->inDepartment($department)->create();
    $department->managers()->attach($head);
    $this->employee->forceFill(['department_id' => $department->id, 'hourly_cost' => '20.00'])->save();
    $this->web->update(['hourly_rate' => '60.00']);
    $this->web->addMember($this->employee);
    $this->other->addMember($this->employee);

    $web = TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $this->web->id]))->on('2026-09-07')->minutes(60)->create(['user_id' => $this->employee->id]);
    $other = TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $this->other->id]))->on('2026-09-08')->minutes(30)->create(['user_id' => $this->employee->id]);

    // Semana del 07/09 enviada y aprobada por su responsable.
    $this->actingAs($this->employee)->post('/horas/semana/enviar', ['week' => '2026-W37'])->assertSessionHasNoErrors();
    $period = TimesheetPeriod::query()->where('user_id', $this->employee->id)->sole();
    $this->actingAs($head)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();
    expect($web->fresh()->hourly_rate_snapshot)->toBe('60.00');

    // Se factura solo la web: la semana sigue aprobada (la otra entrada no está bloqueada).
    $this->actingAs($this->admin)->post('/horas/bloqueo', ['project_id' => $this->web->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'])->assertSessionHasNoErrors();
    $lock = TimeEntryLock::query()->sole();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Approved)
        ->and($web->fresh()->status)->toBe(TimeEntryStatus::Locked);

    // El responsable la reabre: la otra vuelve a borrador y la bloqueada no cambia.
    $this->actingAs($head)->post("/horas/semanas/{$period->id}/reabrir")->assertSessionHasNoErrors();
    expect($period->fresh()->status)->toBe(TimesheetStatus::Open)
        ->and($other->fresh()->status)->toBe(TimeEntryStatus::Draft)
        ->and($web->fresh()->status)->toBe(TimeEntryStatus::Locked);

    // Al desbloquear, la entrada sigue a su semana (abierta): borrador, sin aprobación ni instantáneas.
    $this->actingAs($this->admin)->delete("/horas/bloqueo/{$lock->id}")->assertSessionHasNoErrors();
    $web->refresh();
    expect($web->status)->toBe(TimeEntryStatus::Draft)
        ->and($web->approved_by)->toBeNull()
        ->and($web->approved_at)->toBeNull()
        ->and($web->hourly_rate_snapshot)->toBeNull()
        ->and($web->hourly_cost_snapshot)->toBeNull()
        ->and($web->time_entry_lock_id)->toBeNull()
        ->and($period->fresh()->status)->toBe(TimesheetStatus::Open);

    $activity = Activity::query()->where('subject_type', $lock->getMorphClass())->where('event', 'unlocked')->sole();
    expect($activity->properties['draft'])->toBe(1)
        ->and($activity->properties['approved'])->toBe(0);

    // Su dueño la cambia: sigue en borrador y, al enviar y aprobar, se congela de nuevo con la tarifa de hoy.
    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$web->id}", ['task_id' => $web->task_id, 'date' => '2026-09-07', 'minutes' => 90])
        ->assertSessionHasNoErrors();
    expect($web->fresh()->minutes)->toBe(90)
        ->and($web->fresh()->status)->toBe(TimeEntryStatus::Draft);

    $this->web->update(['hourly_rate' => '70.00']);
    $this->actingAs($this->employee)->post('/horas/semana/enviar', ['week' => '2026-W37'])->assertSessionHasNoErrors();
    expect($web->fresh()->status)->toBe(TimeEntryStatus::Submitted);
    $this->actingAs($head)->post("/horas/aprobaciones/{$period->id}/aprobar")->assertSessionHasNoErrors();

    $web->refresh();
    expect($web->status)->toBe(TimeEntryStatus::Approved)
        ->and($web->approved_by)->toBe($head->id)
        ->and($web->hourly_rate_snapshot)->toBe('70.00')
        ->and($web->hourly_cost_snapshot)->toBe('20.00');
});

it('al desbloquear, cada entrada vuelve al estado de su semana', function (?TimesheetStatus $week, TimeEntryStatus $expected) {
    $lock = TimeEntryLock::query()->create([
        'project_id' => $this->web->id,
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'locked_by' => $this->admin->id,
        'entries_count' => 1,
    ]);
    $entry = ($this->entry)($this->web, '2026-09-13', TimeEntryStatus::Locked); // domingo
    $entry->forceFill([
        'time_entry_lock_id' => $lock->id,
        'approved_by' => $this->admin->id,
        'approved_at' => now(),
        'hourly_rate_snapshot' => '50.00',
        'hourly_cost_snapshot' => '20.00',
    ])->save();
    if ($week !== null) {
        TimesheetPeriod::factory()->for($this->employee)->week('2026-09-07')->status($week)->create();
    }

    $this->actingAs($this->admin)->delete("/horas/bloqueo/{$lock->id}")->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->status)->toBe($expected)
        ->and($entry->locked_at)->toBeNull()
        ->and($entry->time_entry_lock_id)->toBeNull();

    if ($expected === TimeEntryStatus::Approved) {
        expect($entry->approved_by)->toBe($this->admin->id)
            ->and($entry->hourly_rate_snapshot)->toBe('50.00');
    } else {
        expect($entry->approved_by)->toBeNull()
            ->and($entry->approved_at)->toBeNull()
            ->and($entry->hourly_rate_snapshot)->toBeNull()
            ->and($entry->hourly_cost_snapshot)->toBeNull();
    }

    if ($week !== null) {
        expect(TimesheetPeriod::query()->sole()->status)->toBe($week === TimesheetStatus::Locked ? TimesheetStatus::Approved : $week);
    }
})->with([
    'semana bloqueada' => [TimesheetStatus::Locked, TimeEntryStatus::Approved],
    'semana aprobada' => [TimesheetStatus::Approved, TimeEntryStatus::Approved],
    'semana enviada' => [TimesheetStatus::Submitted, TimeEntryStatus::Submitted],
    'semana devuelta' => [TimesheetStatus::Returned, TimeEntryStatus::Draft],
    'semana abierta' => [TimesheetStatus::Open, TimeEntryStatus::Draft],
    'sin semana' => [null, TimeEntryStatus::Draft],
]);

it('una entrada aprobada que quedara en una semana abierta se trata como pendiente al enviar', function () {
    $this->web->addMember($this->employee);
    $entry = ($this->entry)($this->web, '2026-09-21', TimeEntryStatus::Approved);
    $entry->forceFill(['approved_by' => $this->admin->id, 'approved_at' => now(), 'hourly_rate_snapshot' => '50.00', 'hourly_cost_snapshot' => '20.00'])->save();

    $this->actingAs($this->employee)->post('/horas/semana/enviar', ['week' => '2026-W39'])->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->status)->toBe(TimeEntryStatus::Submitted)
        ->and($entry->approved_by)->toBeNull()
        ->and($entry->hourly_rate_snapshot)->toBeNull()
        ->and($entry->hourly_cost_snapshot)->toBeNull();
});

it('desbloquear recalcula la bolsa: el exceso de las entradas bloqueadas ya no está congelado', function () {
    $bank = HourBank::factory()->create(['project_id' => $this->web->id, 'total_minutes' => 600]);
    $task = Task::factory()->create(['project_id' => $this->web->id, 'hour_bank_id' => $bank->id]);
    $entry = TimeEntry::factory()->forTask($task)->on('2026-09-07')->minutes(360)->status(TimeEntryStatus::Approved)->create(['user_id' => $this->employee->id]);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-07')->status(TimesheetStatus::Approved)->create();
    $this->actingAs($this->admin)->post('/horas/bloqueo', ['project_id' => $this->web->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
    $lock = TimeEntryLock::query()->sole();

    // Mientras está bloqueada, bajan el total de la bolsa: su exceso sigue congelado en 0.
    $bank->fresh()->update(['total_minutes' => 300]);
    expect($entry->fresh()->overage_minutes)->toBe(0);

    $this->actingAs($this->admin)->delete("/horas/bloqueo/{$lock->id}")->assertSessionHasNoErrors();

    expect($entry->fresh()->overage_minutes)->toBe(60)
        ->and($bank->fresh()->overage_minutes)->toBe(60);
});

it('solo un admin bloquea, desbloquea o ve la página (D-034)', function (string $role) {
    ($this->entry)($this->web, '2026-09-07', TimeEntryStatus::Approved);
    $lock = TimeEntryLock::query()->create([
        'client_id' => $this->client->id,
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'locked_by' => $this->admin->id,
    ]);
    $user = userWithRole($role);

    $this->actingAs($user)->get('/horas/bloqueo')->assertForbidden();
    $this->actingAs($user)->get("/horas/bloqueo/vista-previa?client_id={$this->client->id}&date_from=2026-09-01&date_to=2026-09-30")->assertForbidden();
    $this->actingAs($user)->post('/horas/bloqueo', ['client_id' => $this->client->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'])->assertForbidden();
    $this->actingAs($user)->delete("/horas/bloqueo/{$lock->id}")->assertForbidden();

    expect(TimeEntry::query()->where('status', 'locked')->count())->toBe(0);
})->with(['department_manager', 'employee']);

it('la página lista los bloqueos con quién y cuándo, sin N+1', function () {
    foreach (['2026-06', '2026-07', '2026-08'] as $month) {
        TimeEntryLock::query()->create([
            'client_id' => $this->client->id,
            'date_from' => "{$month}-01",
            'date_to' => "{$month}-28",
            'locked_by' => $this->admin->id,
            'entries_count' => 4,
            'reference' => "F-{$month}",
            'unlocked_at' => $month === '2026-06' ? now() : null,
            'unlocked_by' => $month === '2026-06' ? $this->admin->id : null,
        ]);
    }
    TimeEntryLock::query()->create(['project_id' => $this->web->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-15', 'locked_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->get('/horas/bloqueo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('time/locks', false)
            ->has('locks', 4)
            ->where('locks.0.project.code', 'ACME-WEB')
            ->where('locks.1.reference', 'F-2026-08')
            ->where('locks.1.client.name', 'ACME')
            ->where('locks.1.locked_by.id', $this->admin->id)
            ->where('locks.3.unlocked_by.id', $this->admin->id)
            ->where('preview', null)
            ->has('clients')
            ->has('projects', 3));
});
