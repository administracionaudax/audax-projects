<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankAlertRecipients;
use App\Enums\ProjectAlert;
use App\Listeners\HourBanks\NotifyHourBankOverageRecorded;
use App\Listeners\HourBanks\NotifyHourBankThresholdReached;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\HourBanks\HourBankOverageNotification;
use App\Notifications\HourBanks\HourBankThresholdNotification;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Avisos de bolsa (SPEC §8.5 y §8.6, D-023, D-035): en la app y por email (cola `mail`) a los
| gestores con esa alerta activada, a los responsables del departamento de la bolsa y a los
| admins; solo internos activos y sin duplicados. Umbrales una vez; exceso como mucho uno al día.
*/

beforeEach(function () {
    Notification::fake();

    $this->department = Department::factory()->create(['name' => 'Desarrollo']);
    $this->otherDepartment = Department::factory()->create(['name' => 'Marketing']);

    $this->owner = userWithRole('employee', ['name' => 'Laura Gestora']);
    $this->coManager = userWithRole('employee', ['name' => 'Co Gestor']);
    $this->quietManager = userWithRole('employee', ['name' => 'Gestor Sin Alertas']);
    $this->member = userWithRole('employee', ['name' => 'Solo Miembro']);
    $this->admin = userWithRole('admin');
    $this->inactiveAdmin = User::factory()->admin()->inactive()->create();

    $this->lead = userWithRole('department_manager');
    $this->lead->managedDepartments()->attach($this->department->id);
    $this->otherLead = userWithRole('department_manager');
    $this->otherLead->managedDepartments()->attach($this->otherDepartment->id);

    $this->project = Project::factory()->hourBank()->create([
        'owner_user_id' => $this->owner->id,
        'code' => 'ACME-WEB',
        'name' => 'Web corporativa',
    ]);
    $this->project->addMember($this->coManager, true);
    $this->project->addMember($this->quietManager, true, [
        ProjectAlert::HourBankThreshold->value => false,
        ProjectAlert::HourBankOverage->value => false,
    ]);
    $this->project->addMember($this->member);

    $this->bank = HourBank::factory()->hours(10)->allowOverage()->create([
        'project_id' => $this->project->id,
        'department_id' => $this->department->id,
        'name' => 'Bolsa T4',
    ]);
    $this->task = Task::factory()->inBank($this->bank)->create();
    $this->log = fn (int $minutes, string $date = '2026-09-24') => TimeEntry::factory()
        ->forTask($this->task)->minutes($minutes)->on($date)->create();
});

test('umbral: avisa a los gestores con la alerta, a los responsables del departamento y a los admins', function () {
    ($this->log)(8 * 60);

    foreach ([$this->owner, $this->coManager, $this->lead, $this->admin] as $user) {
        Notification::assertSentToTimes($user, HourBankThresholdNotification::class, 1);
    }

    foreach ([$this->quietManager, $this->member, $this->otherLead, $this->inactiveAdmin] as $user) {
        Notification::assertNotSentTo($user, HourBankThresholdNotification::class);
    }
});

test('solo el umbral más alto que se cruza, y cada umbral una sola vez (D-035)', function () {
    ($this->log)(9 * 60 + 30);

    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class, fn ($n) => $n->threshold === 90);
    Notification::assertSentToTimes($this->owner, HourBankThresholdNotification::class, 1);

    ($this->log)(1);
    Notification::assertSentToTimes($this->owner, HourBankThresholdNotification::class, 1);

    ($this->log)(30);
    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class, fn ($n) => $n->threshold === 100);
    Notification::assertSentToTimes($this->owner, HourBankThresholdNotification::class, 2);
});

test('sin duplicados: quien es admin y gestor recibe un solo aviso', function () {
    $this->project->addMember($this->admin, true);
    $this->lead->assignRole('admin');

    ($this->log)(8 * 60);

    Notification::assertSentToTimes($this->admin, HourBankThresholdNotification::class, 1);
    Notification::assertSentToTimes($this->lead, HourBankThresholdNotification::class, 1);
});

test('una bolsa sin departamento avisa a sus gestores y a los admins (D-035)', function () {
    $this->bank->update(['department_id' => null]);

    ($this->log)(8 * 60);

    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class);
    Notification::assertSentTo($this->admin, HourBankThresholdNotification::class);
    Notification::assertNotSentTo($this->lead, HourBankThresholdNotification::class);
});

test('los gestores desactivados o que ya no son gestores no reciben avisos', function () {
    $this->coManager->update(['is_active' => false]);
    $this->project->members()->updateExistingPivot($this->owner->id, ['is_manager' => false]);

    ($this->log)(8 * 60);

    Notification::assertNotSentTo($this->coManager, HourBankThresholdNotification::class);
    Notification::assertNotSentTo($this->owner, HourBankThresholdNotification::class);
});

test('exceso: como mucho un aviso al día por bolsa, con las preferencias de exceso', function () {
    $this->project->members()->updateExistingPivot($this->coManager->id, [
        'alert_preferences' => [ProjectAlert::HourBankThreshold->value => true, ProjectAlert::HourBankOverage->value => false],
    ]);

    Carbon::setTestNow('2026-09-24 10:00:00');
    ($this->log)(10 * 60);
    ($this->log)(30);
    ($this->log)(15);

    Notification::assertSentToTimes($this->owner, HourBankOverageNotification::class, 1);
    Notification::assertSentTo($this->owner, HourBankOverageNotification::class, fn ($n) => $n->addedOverageMinutes === 30);
    Notification::assertNotSentTo($this->coManager, HourBankOverageNotification::class);
    Notification::assertNotSentTo($this->quietManager, HourBankOverageNotification::class);
    Notification::assertSentTo($this->lead, HourBankOverageNotification::class);

    Carbon::setTestNow('2026-09-25 09:00:00');
    ($this->log)(10, '2026-09-25');

    Notification::assertSentToTimes($this->owner, HourBankOverageNotification::class, 2);

    Carbon::setTestNow();
});

test('en la app y por email; el email por la cola `mail`', function () {
    ($this->log)(8 * 60);

    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class, function ($notification, array $channels) {
        return $channels === ['database', 'mail']
            && $notification->viaQueues()['mail'] === 'mail'
            && $notification->viaQueues()['database'] === 'default';
    });
});

test('contenido del aviso de umbral: kind, título, cuerpo en h:mm y enlace a la bolsa', function () {
    ($this->log)(7 * 60 + 30);

    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class, function (HourBankThresholdNotification $notification) {
        $data = $notification->toArray($this->owner);

        return $data['kind'] === 'hour_bank.threshold'
            && $data['title'] === 'La bolsa «Bolsa T4» ha llegado al 75 %'
            && $data['body'] === 'ACME-WEB · Web corporativa. Consumidas 7:30 de 10:00; quedan 2:30.'
            && $data['url'] === "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}"
            && $data['icon'] === 'gauge';
    });
});

test('contenido del aviso de exceso', function () {
    ($this->log)(10 * 60 + 45);

    Notification::assertSentTo($this->owner, HourBankOverageNotification::class, function (HourBankOverageNotification $notification) {
        $data = $notification->toArray($this->owner);

        return $data['kind'] === 'hour_bank.overage'
            && $data['title'] === 'Horas en exceso en la bolsa «Bolsa T4»'
            && str_contains((string) $data['body'], 'Se han registrado 0:45 de exceso')
            && $data['url'] === "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}";
    });
});

test('el email va en español, con el enlace y el nombre de la empresa', function () {
    Setting::set('company_name', 'Audax Studio');
    ($this->log)(8 * 60);

    Notification::assertSentTo($this->owner, HourBankThresholdNotification::class, function (HourBankThresholdNotification $notification) {
        $mail = $notification->toMail($this->owner);

        return $mail->subject === 'La bolsa «Bolsa T4» ha llegado al 75 %'
            && $mail->greeting === 'Hola, Laura Gestora:'
            && $mail->actionText === 'Ver la bolsa'
            && str_ends_with((string) $mail->actionUrl, "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}")
            && str_contains((string) $mail->salutation, 'Audax Studio')
            && in_array('ACME-WEB · Web corporativa. Consumidas 8:00 de 10:00; quedan 2:00.', $mail->introLines, true);
    });
});

test('los destinatarios nunca incluyen usuarios del portal de clientes', function () {
    $client = userWithRole('client');
    $this->project->addMember($client, true);

    $recipients = app(HourBankAlertRecipients::class)->for($this->bank, ProjectAlert::HourBankThreshold);

    expect($recipients->pluck('id'))->not->toContain($client->id)
        ->and($recipients->pluck('id')->all())->toEqualCanonicalizing([$this->owner->id, $this->coManager->id, $this->lead->id, $this->admin->id]);
});

test('los listeners se registran solos para los eventos del motor de bolsas', function () {
    Event::fake();

    Event::assertListening(HourBankThresholdReached::class, NotifyHourBankThresholdReached::class);
    Event::assertListening(HourBankOverageRecorded::class, NotifyHourBankOverageRecorded::class);
});

test('se envía de verdad: queda en la base de datos para la campana y el email se genera', function () {
    // Sin el fake: la cola es síncrona en los tests y el correo va al mailer «array».
    Notification::swap(new ChannelManager(app()));

    ($this->log)(8 * 60);

    $stored = $this->owner->notifications()->first();

    expect($stored)->not->toBeNull()
        ->and($stored?->data['kind'])->toBe('hour_bank.threshold')
        ->and($stored?->data['url'])->toBe("/proyectos/{$this->project->id}/bolsas/{$this->bank->id}");
});
