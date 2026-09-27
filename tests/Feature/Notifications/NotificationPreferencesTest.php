<?php

use App\Domain\Notifications\NotificationPreferences;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/*
| Preferencias por canal (SPEC §13, D-073): NotificationPreferences decide los canales de toda
| AppNotification. Sin tocar nada, cada aviso sigue llegando por donde llegaba.
*/

beforeEach(function () {
    $this->preferences = app(NotificationPreferences::class);
    $this->employee = User::factory()->employee()->create(['name' => 'Ana Ruiz']);
    $this->notificationFor = fn (string $kind, ?string $url = '/tareas/7'): AppNotification => new class($kind, $url) extends AppNotification
    {
        public function __construct(private string $eventKind, private ?string $link) {}

        public function kind(): string
        {
            return $this->eventKind;
        }

        public function title(object $notifiable): string
        {
            return 'Te han asignado «Maquetar la portada»';
        }

        public function body(object $notifiable): ?string
        {
            return 'Proyecto ACME-WEB';
        }

        public function url(object $notifiable): ?string
        {
            return $this->link;
        }
    };
});

test('por defecto cada evento llega por los canales de siempre y los que añade D-073', function (string $kind, array $channels) {
    expect($this->preferences->channelsFor($this->employee, $kind))->toBe($channels);
})->with([
    'tarea asignada' => ['task.assigned', ['database']],
    'mención en una tarea' => ['task.mentioned', ['database']],
    'tareas que vencen' => ['task.due', ['database', 'mail']],
    'semana devuelta' => ['time.returned', ['database', 'mail']],
    'semana aprobada' => ['time.approved', ['database']],
    'umbral de bolsa' => ['hour_bank.threshold', ['database', 'mail']],
    'ausencia aprobada' => ['absence.approved', ['database', 'mail']],
    'resumen semanal' => ['reports.weekly_digest', ['mail', 'database']],
    'mensaje directo sin Web Push configurado' => ['chat.direct', ['database']],
]);

test('AppNotification::via pregunta a las preferencias', function () {
    $notification = ($this->notificationFor)('task.due');

    expect($notification->via($this->employee))->toBe(['database', 'mail']);

    $this->preferences->update($this->employee, ['task.due' => ['email' => false]], false);

    expect($notification->via($this->employee->refresh()))->toBe(['database']);
});

test('fuera del catálogo o sin usuario, la notificación usa sus canales de reserva', function () {
    expect(($this->notificationFor)('desconocido.evento')->via($this->employee))->toBe(['database'])
        ->and($this->preferences->channelsFor(new stdClass, 'task.assigned'))->toBeNull();
});

test('se guarda solo lo que difiere del catálogo e ignora lo que no se puede cambiar', function () {
    $this->preferences->update($this->employee, [
        'task.assigned' => ['email' => true, 'app' => true],
        'reports.weekly_digest' => ['push' => true],
        'system.disk_space' => ['app' => false],
        'no.existe' => ['app' => false],
    ], false);

    expect($this->employee->refresh()->notification_preferences)->toBe([
        'events' => ['task.assigned' => ['email' => true]],
        'daily_digest' => false,
    ])->and($this->preferences->channelsFor($this->employee, 'task.assigned'))->toBe(['database', 'mail']);

    $this->preferences->update($this->employee, ['task.assigned' => ['email' => false]], false);

    expect($this->employee->refresh()->notification_preferences['events'])->toBe([]);
});

test('los avisos obligatorios del admin no se pueden desactivar', function () {
    $admin = User::factory()->admin()->create();

    $this->preferences->update($admin, ['system.disk_space' => ['app' => false, 'email' => false]], true);

    expect($this->preferences->channelsFor($admin->refresh(), 'system.disk_space'))->toBe(['database', 'mail']);
});

test('con el resumen diario, el email de los eventos no obligatorios se queda en la campana', function () {
    $this->preferences->update($this->employee, [], true);
    $this->employee->refresh();

    expect($this->preferences->dailyDigest($this->employee))->toBeTrue()
        ->and($this->preferences->channelsFor($this->employee, 'absence.approved'))->toBe(['database'])
        ->and($this->preferences->channelsFor($this->employee, 'task.assigned'))->toBe(['database'])
        ->and($this->preferences->digestKinds($this->employee))->toContain('absence.approved', 'task.due')
        ->and($this->preferences->digestKinds($this->employee))->not->toContain('task.assigned');
});

test('Web Push solo se ofrece y se envía si su canal está configurado', function () {
    expect($this->preferences->pushAvailable())->toBeFalse()
        ->and($this->preferences->forUser($this->employee)['groups'][0]['events'][0]['channels']['push'])->toBe(['offered' => false, 'enabled' => false]);

    config(['notifications.channels.push' => 'webpush-prueba']);

    expect($this->preferences->pushAvailable())->toBeTrue()
        ->and($this->preferences->channelsFor($this->employee, 'chat.direct'))->toBe(['database', 'webpush-prueba'])
        ->and($this->preferences->channelsFor($this->employee, 'task.mentioned'))->toBe(['database', 'webpush-prueba']);
});

test('a cada persona solo se le ofrecen sus eventos', function () {
    $kinds = fn (User $user): array => collect($this->preferences->forUser($user)['groups'])
        ->flatMap(fn (array $group): array => array_column($group['events'], 'kind'))
        ->all();

    $manager = User::factory()->departmentManager()->create();
    $projectManager = User::factory()->employee()->create();
    ProjectMember::query()->create(['project_id' => Project::factory()->create()->id, 'user_id' => $projectManager->id, 'is_manager' => true]);
    $admin = User::factory()->admin()->create();
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    expect($kinds($this->employee))->toContain('task.assigned', 'absence.approved', 'chat.direct')
        ->not->toContain('hour_bank.threshold', 'absence.requested', 'reports.weekly_digest', 'system.disk_space')
        ->and($kinds($projectManager))->toContain('hour_bank.threshold', 'reports.weekly_digest')->not->toContain('absence.requested')
        ->and($kinds($manager))->toContain('hour_bank.threshold', 'absence.requested')->not->toContain('system.disk_space')
        ->and($kinds($admin))->toContain('system.disk_space', 'system.backup_failed', 'absence.requested')
        ->and($this->preferences->forUser($client)['groups'])->toBe([]);

    // Lo que no se le ofrece no se guarda.
    $this->preferences->update($this->employee, ['hour_bank.threshold' => ['email' => false]], false);

    expect($this->employee->refresh()->notification_preferences['events'])->toBe([]);
});

test('las preferencias para la página llevan grupos ordenados, textos y canales', function () {
    $settings = $this->preferences->forUser($this->employee);

    expect($settings['daily_digest'])->toBeFalse()
        ->and($settings['push_available'])->toBeFalse()
        ->and(array_column($settings['groups'], 'key'))->toBe(['tasks', 'time', 'absences', 'chat'])
        ->and($settings['groups'][0]['label'])->toBe('Tareas')
        ->and($settings['groups'][0]['events'][0])->toMatchArray([
            'kind' => 'task.assigned',
            'label' => 'Te asignan una tarea',
            'mandatory' => false,
            'channels' => [
                'app' => ['offered' => true, 'enabled' => true],
                'email' => ['offered' => true, 'enabled' => false],
                'push' => ['offered' => false, 'enabled' => false],
            ],
        ]);
});

test('un valor guardado corrupto se ignora', function () {
    $this->employee->forceFill(['notification_preferences' => ['events' => 'x', 'daily_digest' => 'sí']])->save();

    expect($this->preferences->dailyDigest($this->employee))->toBeFalse()
        ->and($this->preferences->channelsFor($this->employee, 'task.due'))->toBe(['database', 'mail']);

    $this->employee->forceFill(['notification_preferences' => ['events' => ['task.due' => ['email' => 'no', 'fax' => true]]]])->save();

    expect($this->preferences->channelsFor($this->employee, 'task.due'))->toBe(['database', 'mail']);
});

test('toda AppNotification tiene un email genérico y un aviso Web Push', function () {
    $notification = ($this->notificationFor)('task.assigned');
    $mail = $notification->toMail($this->employee);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Te han asignado «Maquetar la portada»')
        ->and($mail->greeting)->toBe('Hola, Ana Ruiz:')
        ->and($mail->introLines)->toBe(['Te han asignado «Maquetar la portada»', 'Proyecto ACME-WEB'])
        ->and($mail->actionUrl)->toBe(url('/tareas/7'))
        ->and($notification->toPushPayload($this->employee))->toBe([
            'title' => 'Te han asignado «Maquetar la portada»',
            'body' => 'Proyecto ACME-WEB',
            'url' => '/tareas/7',
            'tag' => 'task.assigned',
        ])
        ->and(($this->notificationFor)('task.assigned', null)->toPushPayload($this->employee)['url'])->toBe('/notificaciones');
});
