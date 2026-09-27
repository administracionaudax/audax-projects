<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Notifications\Portal\ClientHourBankThreshold;
use App\Notifications\Time\TimesheetApproved;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/*
| Avisos al cliente (D-065) por los flujos reales: la imputación (TimeEntryWriter, POST y PUT
| /horas/entradas) y la aprobación de la semana. Se comprueban cuando la escritura interna ya está
| confirmada y NUNCA la rompen: con el cliente desactivado no se avisa (y la petición acaba bien),
| y un fallo al avisar solo se registra.
|
| Bolsa de 600 min de un cliente con los avisos activados, un usuario del portal activo y otro
| revocado; Elena (empleada del departamento de Rosa, su responsable) es miembro del proyecto.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $department = Department::factory()->create();
    $this->head = User::factory()->departmentManager()->inDepartment($department)->create(['name' => 'Rosa']);
    $department->managers()->attach($this->head);
    $this->employee = User::factory()->employee()->inDepartment($department)->create(['name' => 'Elena']);
    $this->admin = userWithRole('admin');

    $this->client = Client::factory()->create(['portal_notify_thresholds' => true]);
    $this->portalUser = User::factory()->portalOf($this->client)->create();
    $this->revoked = User::factory()->portalOf($this->client)->create(['is_active' => false]);

    $this->project = Project::factory()->hourBank()->create(['client_id' => $this->client->id]);
    $this->project->addMember($this->employee);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id, 'total_minutes' => 600, 'start_date' => '2026-01-01']);
    $this->task = Task::factory()->inBank($this->bank)->create();

    // Elena imputa 560 min el lunes y envía la semana (sin aprobar, el cliente no los ve).
    $this->submitWeek = function (int $minutes = 560): TimesheetPeriod {
        $this->actingAs($this->employee)->from('/horas')->post('/horas/entradas', [
            'task_id' => $this->task->id, 'date' => '2026-09-21', 'minutes' => $minutes, 'description' => 'Diseño',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->post('/horas/semana/enviar', ['week' => '2026-W39'])->assertSessionHasNoErrors();

        return TimesheetPeriod::query()->where('user_id', $this->employee->id)->firstOrFail();
    };
    $this->approve = fn (TimesheetPeriod $period) => $this->actingAs($this->head)
        ->from('/horas/aprobaciones')
        ->post("/horas/aprobaciones/{$period->id}/aprobar");
});

it('aprobar la semana que lleva la bolsa al 90 % avisa a los usuarios activos del portal', function () {
    $period = ($this->submitWeek)();
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);

    ($this->approve)($period)->assertRedirect('/horas/aprobaciones');

    Notification::assertSentTo($this->portalUser, ClientHourBankThreshold::class, fn (ClientHourBankThreshold $n) => $n->threshold === 90 && $n->figures['within_minutes'] === 560);
    Notification::assertNotSentTo($this->revoked, ClientHourBankThreshold::class);
    Notification::assertSentTo($this->employee, TimesheetApproved::class);
});

it('con el cliente desactivado, aprobar la semana acaba bien, avisa a la persona y no al cliente', function () {
    $period = ($this->submitWeek)();
    $this->client->update(['is_active' => false]);

    ($this->approve)($period)->assertRedirect('/horas/aprobaciones')->assertSessionHasNoErrors();

    expect($period->fresh()->status)->toBe(TimesheetStatus::Approved)
        ->and(TimeEntry::query()->sole()->status)->toBe(TimeEntryStatus::Approved)
        ->and(HourBankAlert::query()->where('key', 'like', 'client:%')->exists())->toBeFalse();
    Notification::assertSentTo($this->employee, TimesheetApproved::class);
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);
});

it('con el cliente desactivado, imputar en su bolsa acaba bien y guarda una sola entrada', function () {
    $this->client->update(['is_active' => false]);

    $this->actingAs($this->employee)->from('/horas')->post('/horas/entradas', [
        'task_id' => $this->task->id, 'date' => '2026-09-24', 'minutes' => 15, 'description' => 'Revisión',
    ])->assertRedirect('/horas')->assertSessionHasNoErrors();

    expect(TimeEntry::query()->count())->toBe(1);
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);
});

it('un admin que corrige una entrada bloqueada (TimeEntryWriter) avisa al cliente; desactivado, no', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-01')->minutes(500)->status(TimeEntryStatus::Locked)->create(['user_id' => $this->employee->id]);
    $update = fn (int $minutes) => $this->actingAs($this->admin)->from('/horas')->put("/horas/entradas/{$entry->id}", [
        'task_id' => $this->task->id, 'date' => '2026-09-01', 'minutes' => $minutes, 'description' => 'Diseño',
    ]);

    $this->client->update(['is_active' => false]);
    $update(550)->assertRedirect('/horas')->assertSessionHasNoErrors();
    expect($entry->fresh()->minutes)->toBe(550);
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);

    $this->client->update(['is_active' => true]);
    $update(560)->assertRedirect('/horas')->assertSessionHasNoErrors();
    Notification::assertSentTo($this->portalUser, ClientHourBankThreshold::class, fn (ClientHourBankThreshold $n) => $n->threshold === 90);
});

it('un fallo al avisar se registra y nunca rompe la escritura interna', function () {
    Exceptions::fake();
    // Un correo que no se puede encolar (por ejemplo, la cola caída).
    Notification::swap(new class extends NotificationFake
    {
        public function send($notifiables, $notification)
        {
            throw new RuntimeException('La cola de correo no responde');
        }
    });
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-01')->minutes(500)->status(TimeEntryStatus::Locked)->create(['user_id' => $this->employee->id]);

    $this->actingAs($this->admin)->from('/horas')->put("/horas/entradas/{$entry->id}", [
        'task_id' => $this->task->id, 'date' => '2026-09-01', 'minutes' => 560, 'description' => 'Diseño',
    ])->assertRedirect('/horas')->assertSessionHasNoErrors();

    expect($entry->fresh()->minutes)->toBe(560)
        ->and(TimeEntry::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'La cola de correo no responde');
});

it('una escritura que se deshace no deja la bolsa apuntada: la siguiente sí se comprueba', function () {
    $approved = fn () => TimeEntry::factory()->forTask($this->task)->on('2026-09-01')->minutes(560)->status(TimeEntryStatus::Approved)->create(['user_id' => $this->employee->id]);

    expect(fn () => DB::transaction(function () use ($approved): void {
        $approved();

        throw new RuntimeException('Algo falla después de guardar');
    }))->toThrow(RuntimeException::class);
    expect(TimeEntry::query()->count())->toBe(0);
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);

    $approved();
    Notification::assertSentTo($this->portalUser, ClientHourBankThreshold::class, fn (ClientHourBankThreshold $n) => $n->threshold === 90);
});

it('no avisa de una bolsa cerrada o renovada aunque se aprueben sus horas (D-053)', function (HourBankStatus $status) {
    $period = ($this->submitWeek)(600);
    $this->bank->forceFill(['status' => $status])->save();

    ($this->approve)($period)->assertRedirect('/horas/aprobaciones')->assertSessionHasNoErrors();

    expect(TimeEntry::query()->sole()->status)->toBe(TimeEntryStatus::Approved)
        ->and(HourBankAlert::query()->where('key', 'like', 'client:%')->exists())->toBeFalse();
    Notification::assertNotSentTo($this->portalUser, ClientHourBankThreshold::class);
})->with([
    'renovada' => [HourBankStatus::Renewed],
    'cerrada' => [HourBankStatus::Closed],
]);
