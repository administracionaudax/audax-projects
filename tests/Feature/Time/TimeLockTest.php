<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
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
